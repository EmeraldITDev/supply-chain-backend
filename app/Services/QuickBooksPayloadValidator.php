<?php

namespace App\Services;

use App\Exceptions\QuickBooksException;

class QuickBooksPayloadValidator
{
    public function __construct(private QuickBooksService $quickBooks) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return array{passed: bool, results: list<array{check: string, passed: bool, detail: string}>, assumptions: list<string>}
     */
    public function validate(array $payload, array $meta, bool $remote = true, ?int $mrfId = null): array
    {
        $results = $this->localResults($payload, $meta);
        if ($remote) {
            $results = array_merge($results, $this->remoteResults($payload, $meta, $mrfId));
        }

        $passed = collect($results)->every(fn (array $result) => $result['passed'] === true);

        return [
            'passed' => $passed,
            'results' => $results,
            'assumptions' => array_values($meta['assumptions'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    private function localResults(array $payload, array $meta): array
    {
        $results = [];
        $poNumber = trim((string) ($meta['po_number'] ?? ''));
        $max = (int) config('services.quickbooks.doc_number_max_length', 21);
        $docNumber = isset($payload['DocNumber']) ? (string) $payload['DocNumber'] : null;

        if ($poNumber !== '' && mb_strlen($poNumber) > $max) {
            $note = (string) ($payload['PrivateNote'] ?? '');
            $memo = (string) ($payload['VendorMemo'] ?? '');
            $kept = ! array_key_exists('DocNumber', $payload)
                && str_contains($note, $poNumber)
                && str_contains($memo, $poNumber);
            $results[] = $this->result(
                'doc_number',
                $kept,
                $kept
                    ? "DocNumber omitted because the SCM PO number exceeds {$max} characters. The full number is in PrivateNote and VendorMemo."
                    : "SCM PO number exceeds {$max} characters, so DocNumber must be omitted and the full number kept in PrivateNote and VendorMemo.",
            );
        } elseif ($docNumber === null || $docNumber === '') {
            $results[] = $this->result('doc_number', false, 'DocNumber is missing and the SCM PO number is within the QuickBooks limit.');
        } else {
            $results[] = $this->result(
                'doc_number',
                mb_strlen($docNumber) <= $max,
                mb_strlen($docNumber) <= $max
                    ? 'DocNumber is within the QuickBooks limit.'
                    : "DocNumber is {$docNumber} characters; QuickBooks allows {$max}.",
            );
        }

        $vendorAction = (string) ($meta['vendor_action'] ?? '');
        $vendorRef = data_get($payload, 'VendorRef.value');
        if ($vendorAction === 'would_create') {
            $results[] = $this->result('vendor_ref', true, 'Would create vendor. VendorRef is assigned only on a live push.');
        } else {
            $results[] = $this->result(
                'vendor_ref',
                is_string($vendorRef) && $vendorRef !== '',
                is_string($vendorRef) && $vendorRef !== '' ? 'VendorRef is present.' : 'VendorRef is required.',
            );
        }

        $results[] = $this->result(
            'po_status',
            ($payload['POStatus'] ?? null) === 'Open',
            'POStatus must be Open.',
        );
        $results[] = $this->result(
            'txn_date',
            is_string($payload['TxnDate'] ?? null) && $payload['TxnDate'] !== '',
            'TxnDate is required.',
        );
        $results[] = $this->result(
            'custom_field',
            ! array_key_exists('CustomField', $payload),
            'CustomField is not sent.',
        );

        $lines = is_array($payload['Line'] ?? null) ? $payload['Line'] : [];
        $results[] = $this->result('lines', $lines !== [], 'At least one line is required.');

        $lineMode = (string) ($meta['line_mode'] ?? '');
        if ($lineMode === '') {
            $results[] = $this->result(
                'line_ref',
                false,
                'Configure QUICKBOOKS_DEFAULT_ITEM_ID or QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID. Neither is set.',
            );
        }

        $sum = 0.0;
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                $results[] = $this->result('line_'.($index + 1), false, 'Line is empty.');
                continue;
            }
            $results[] = $this->result(
                'line_'.($index + 1).'_id',
                ! array_key_exists('Id', $line),
                'Line Id is not sent.',
            );

            $amount = $line['Amount'] ?? null;
            // Only the PO discount line may be negative.
            $isDiscountLine = ($meta['discount_line_index'] ?? null) === $index;
            $badAmount = ! is_numeric($amount) || ($isDiscountLine ? (float) $amount >= 0 : (float) $amount < 0);
            $results[] = $this->result(
                'line_'.($index + 1).'_amount',
                ! $badAmount,
                $badAmount
                    ? ($isDiscountLine ? 'Discount line amount must be negative.' : 'Line amount is null or negative.')
                    : 'Line amount is present.',
            );

            if ($lineMode === 'item') {
                $qty = data_get($line, 'ItemBasedExpenseLineDetail.Qty');
                $price = data_get($line, 'ItemBasedExpenseLineDetail.UnitPrice');
                $itemRef = data_get($line, 'ItemBasedExpenseLineDetail.ItemRef.value');
                $math = is_numeric($qty) && is_numeric($price) && is_numeric($amount)
                    && $this->moneyEquals((float) $qty * (float) $price, $amount);
                $results[] = $this->result(
                    'line_'.($index + 1).'_math',
                    $math,
                    $math ? 'Line amount equals quantity times unit price.' : 'Line amount does not equal quantity times unit price.',
                );
                $results[] = $this->result(
                    'line_'.($index + 1).'_item_ref',
                    is_string($itemRef) && $itemRef !== '',
                    is_string($itemRef) && $itemRef !== '' ? 'ItemRef is present.' : 'ItemRef is required and must not be hardcoded.',
                );
            }

            if ($lineMode === 'account') {
                $accountRef = data_get($line, 'AccountBasedExpenseLineDetail.AccountRef.value');
                $source = $meta['source_lines'][$index] ?? null;
                $math = is_array($source)
                    && is_numeric($source['qty'] ?? null)
                    && is_numeric($source['unit_price'] ?? null)
                    && is_numeric($amount)
                    && $this->moneyEquals((float) $source['qty'] * (float) $source['unit_price'], $amount);
                $results[] = $this->result(
                    'line_'.($index + 1).'_math',
                    $math,
                    $math ? 'Line amount equals quantity times unit price.' : 'Line amount does not equal quantity times unit price.',
                );
                $results[] = $this->result(
                    'line_'.($index + 1).'_account_ref',
                    is_string($accountRef) && $accountRef !== '',
                    is_string($accountRef) && $accountRef !== '' ? 'AccountRef is present.' : 'AccountRef is required.',
                );
            }

            if (is_numeric($amount) && ((float) $amount >= 0 || $isDiscountLine)) {
                $sum += round((float) $amount, 2);
            }
        }

        $expected = $meta['expected_total'] ?? null;
        $totalMatches = is_numeric($expected) && $this->moneyEquals($sum, $expected);
        $results[] = $this->result(
            'total',
            $totalMatches,
            $totalMatches
                ? 'The sum of the lines equals the purchase order total.'
                : 'The sum of the lines does not equal the purchase order total.',
        );

        $currency = strtoupper((string) ($meta['currency'] ?? ''));
        $home = strtoupper((string) ($meta['home_currency'] ?? ''));
        $multi = (bool) ($meta['multi_currency'] ?? false);
        if ($home === '') {
            $results[] = $this->result('currency', false, 'QuickBooks home currency could not be read.');
        } elseif ($currency !== $home && ! $multi) {
            $results[] = $this->result(
                'currency',
                false,
                'This purchase order currency differs from the QuickBooks home currency and multi-currency is off.',
            );
        } else {
            $results[] = $this->result('currency', true, 'Currency is compatible with the QuickBooks company.');
        }

        $apAccounts = is_array($meta['ap_accounts'] ?? null) ? $meta['ap_accounts'] : [];
        $apCount = count($apAccounts);
        $apRef = data_get($payload, 'APAccountRef.value');
        if ($apCount > 1) {
            $known = collect($apAccounts)->pluck('Id')->map(fn ($id) => (string) $id)->all();
            $ok = is_string($apRef) && $apRef !== '' && in_array($apRef, $known, true);
            $results[] = $this->result(
                'ap_account',
                $ok,
                $ok
                    ? 'APAccountRef is set because the company has more than one accounts-payable account.'
                    : 'This company has more than one accounts-payable account. Set QUICKBOOKS_AP_ACCOUNT_ID to one of them.',
            );
        } elseif ($apCount === 1) {
            $results[] = $this->result('ap_account', true, 'One accounts-payable account exists, so APAccountRef is omitted.');
        } else {
            $results[] = $this->result('ap_account', false, 'No accounts-payable account was found in QuickBooks.');
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return list<array{check: string, passed: bool, detail: string}>
     */
    private function remoteResults(array $payload, array $meta, ?int $mrfId): array
    {
        $results = [];
        $vendorAction = (string) ($meta['vendor_action'] ?? '');
        $vendorRef = data_get($payload, 'VendorRef.value');
        if ($vendorAction !== 'would_create' && is_string($vendorRef) && $vendorRef !== '') {
            $exists = $this->lookup('vendor', $vendorRef, $mrfId);
            $results[] = $this->result(
                'vendor_exists',
                $exists,
                $exists ? 'VendorRef exists in QuickBooks.' : 'VendorRef was not found in QuickBooks.',
            );
        }

        $lines = is_array($payload['Line'] ?? null) ? $payload['Line'] : [];
        $checkedItems = [];
        $checkedAccounts = [];
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $itemRef = data_get($line, 'ItemBasedExpenseLineDetail.ItemRef.value');
            if (is_string($itemRef) && $itemRef !== '' && ! in_array($itemRef, $checkedItems, true)) {
                $checkedItems[] = $itemRef;
                $exists = $this->lookup('item', $itemRef, $mrfId);
                $results[] = $this->result(
                    'item_ref_'.$itemRef,
                    $exists,
                    $exists ? 'ItemRef exists in QuickBooks.' : 'ItemRef was not found in QuickBooks.',
                );
            }
            $accountRef = data_get($line, 'AccountBasedExpenseLineDetail.AccountRef.value');
            if (is_string($accountRef) && $accountRef !== '' && ! in_array($accountRef, $checkedAccounts, true)) {
                $checkedAccounts[] = $accountRef;
                $exists = $this->lookup('account', $accountRef, $mrfId);
                $results[] = $this->result(
                    'account_ref_'.$accountRef,
                    $exists,
                    $exists ? 'AccountRef exists in QuickBooks.' : 'AccountRef was not found in QuickBooks.',
                );
            }
            unset($index);
        }

        return $results;
    }

    private function lookup(string $entity, string $id, ?int $mrfId): bool
    {
        try {
            return $this->quickBooks->entityExists($entity, $id, $mrfId);
        } catch (QuickBooksException) {
            return false;
        }
    }

    /**
     * @return array{check: string, passed: bool, detail: string}
     */
    private function result(string $check, bool $passed, string $detail): array
    {
        return [
            'check' => $check,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }

    private function moneyEquals(mixed $left, mixed $right): bool
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return false;
        }

        return abs(round((float) $left, 2) - round((float) $right, 2)) < 0.001;
    }
}
