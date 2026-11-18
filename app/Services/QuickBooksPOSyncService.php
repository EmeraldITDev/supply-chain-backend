<?php

namespace App\Services;

use App\Exceptions\QuickBooksException;
use App\Models\MRF;
use App\Models\Vendor;
use App\Models\VendorQuickBooksMapping;
use App\Support\PurchaseOrderCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class QuickBooksPOSyncService
{
    public function __construct(
        private QuickBooksService $quickBooks,
        private QuickBooksPayloadValidator $validator,
        private QuickBooksSupplierService $suppliers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(MRF $mrf, bool $forceDryRun = false, bool $includePayload = false): array
    {
        $lock = Cache::lock('qb_sync_po_'.$mrf->id, 60);
        if (! $lock->get()) {
            throw new QuickBooksException('A QuickBooks sync is already running for this purchase order.', status: 409);
        }

        try {
            return $this->syncLocked($mrf, $forceDryRun, $includePayload);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(MRF $mrf, array $context): array
    {
        $assumptions = [
            'Line amounts are tax-exclusive. QuickBooks tax codes are not sent.',
        ];
        $itemId = trim((string) config('services.quickbooks.default_item_id'));
        $accountId = trim((string) config('services.quickbooks.default_expense_account_id'));
        $lineMode = '';
        if ($itemId !== '') {
            $lineMode = 'item';
            $assumptions[] = 'Lines use ItemBasedExpenseLineDetail and QUICKBOOKS_DEFAULT_ITEM_ID.';
            if ($accountId !== '') {
                $assumptions[] = 'QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID is also set; the item id is used.';
            }
        } elseif ($accountId !== '') {
            $lineMode = 'account';
            $assumptions[] = 'Lines use AccountBasedExpenseLineDetail and QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID.';
        } else {
            $assumptions[] = 'Neither QUICKBOOKS_DEFAULT_ITEM_ID nor QUICKBOOKS_DEFAULT_EXPENSE_ACCOUNT_ID is configured.';
        }

        $poNumber = trim((string) ($mrf->po_number ?? ''));
        $reference = trim((string) ($mrf->formatted_id ?: $mrf->mrf_id));
        $note = trim('SCM reference: '.$reference.'; PO number: '.$poNumber);
        $maxDoc = (int) config('services.quickbooks.doc_number_max_length', 21);
        $payload = [
            'VendorRef' => ['value' => $context['vendor_id'] ?? null],
            'POStatus' => 'Open',
            'TxnDate' => $this->txnDate($mrf),
            'PrivateNote' => mb_substr($note, 0, 4000),
            'VendorMemo' => mb_substr($note, 0, 1000),
            'Line' => [],
        ];
        if ($context['vendor_id'] === null) {
            unset($payload['VendorRef']);
        }

        if ($poNumber !== '' && mb_strlen($poNumber) <= $maxDoc) {
            $payload['DocNumber'] = $poNumber;
        } elseif ($poNumber !== '') {
            $assumptions[] = "DocNumber omitted because \"{$poNumber}\" exceeds {$maxDoc} characters. QuickBooks will auto-number the purchase order.";
        }

        $currency = PurchaseOrderCurrency::normalize($context['po_currency'] ?? $mrf->currency);
        $home = strtoupper((string) ($context['home_currency'] ?? ''));
        $multi = (bool) ($context['multi_currency'] ?? false);
        if ($multi) {
            $payload['CurrencyRef'] = ['value' => $currency];
            $assumptions[] = 'Multi-currency is on, so CurrencyRef is sent.';
        }

        $apAccounts = is_array($context['ap_accounts'] ?? null) ? $context['ap_accounts'] : [];
        if (count($apAccounts) > 1) {
            $configured = trim((string) config('services.quickbooks.ap_account_id'));
            if ($configured !== '') {
                $payload['APAccountRef'] = ['value' => $configured];
            }
            $assumptions[] = 'The company has more than one accounts-payable account, so APAccountRef is required.';
        } elseif (count($apAccounts) === 1) {
            $assumptions[] = 'The company has one accounts-payable account. APAccountRef is omitted and QuickBooks uses that account.';
        }

        $sourceLines = [];
        $lineNum = 0;
        foreach ($mrf->items as $item) {
            $lineNum++;
            $qty = (float) $item->quantity;
            $unit = round((float) $item->unit_price, 2);
            $amount = round($qty * $unit, 2);
            $description = trim(implode(' - ', array_filter([
                trim((string) $item->item_name),
                trim((string) $item->description),
            ])));
            if ($description === '') {
                $description = 'Line '.$lineNum;
            }

            $line = [
                'DetailType' => $lineMode === 'account' ? 'AccountBasedExpenseLineDetail' : 'ItemBasedExpenseLineDetail',
                'Amount' => $amount,
                'Description' => mb_substr($description, 0, 4000),
                'LineNum' => $lineNum,
            ];
            if ($lineMode === 'item') {
                $line['ItemBasedExpenseLineDetail'] = [
                    'ItemRef' => ['value' => $itemId],
                    'Qty' => $qty,
                    'UnitPrice' => $unit,
                ];
            } elseif ($lineMode === 'account') {
                $line['DetailType'] = 'AccountBasedExpenseLineDetail';
                $line['AccountBasedExpenseLineDetail'] = [
                    'AccountRef' => ['value' => $accountId],
                ];
            }
            $payload['Line'][] = $line;
            $sourceLines[] = [
                'qty' => $qty,
                'unit_price' => $unit,
                'amount' => $amount,
            ];
        }

        // PO discount (taken before VAT): QuickBooks purchase orders have no discount
        // line type, so it is sent as one negative line against the same item/account.
        $discountLineIndex = null;
        $discountAmount = is_numeric($mrf->discount_amount) ? round((float) $mrf->discount_amount, 2) : 0.0;
        if ($discountAmount > 0) {
            $lineNum++;
            $line = [
                'DetailType' => $lineMode === 'account' ? 'AccountBasedExpenseLineDetail' : 'ItemBasedExpenseLineDetail',
                'Amount' => -$discountAmount,
                'Description' => 'Discount',
                'LineNum' => $lineNum,
            ];
            if ($lineMode === 'item') {
                $line['ItemBasedExpenseLineDetail'] = [
                    'ItemRef' => ['value' => $itemId],
                    'Qty' => 1,
                    'UnitPrice' => -$discountAmount,
                ];
            } elseif ($lineMode === 'account') {
                $line['AccountBasedExpenseLineDetail'] = [
                    'AccountRef' => ['value' => $accountId],
                ];
            }
            $payload['Line'][] = $line;
            $discountLineIndex = count($payload['Line']) - 1;
            $sourceLines[] = [
                'qty' => 1,
                'unit_price' => -$discountAmount,
                'amount' => -$discountAmount,
            ];
            $assumptions[] = 'The PO discount is sent as one negative "Discount" line.';
        }

        $tax = is_numeric($mrf->tax_amount) ? round((float) $mrf->tax_amount, 2) : 0.0;
        if (is_numeric($mrf->po_value)) {
            $expected = round((float) $mrf->po_value - $tax, 2);
            $assumptions[] = 'Expected total is po_value minus tax_amount, because line amounts are tax-exclusive.';
        } else {
            $expected = round((float) $mrf->estimated_cost, 2);
            $assumptions[] = 'po_value is empty. Expected total is estimated_cost.';
        }

        return [
            'payload' => $payload,
            'meta' => [
                'expected_total' => $expected,
                'po_number' => $poNumber,
                'currency' => $currency,
                'home_currency' => $home !== '' ? $home : null,
                'multi_currency' => $multi,
                'vendor_action' => $context['vendor_action'] ?? null,
                'ap_accounts' => $apAccounts,
                'line_mode' => $lineMode,
                'source_lines' => $sourceLines,
                'discount_line_index' => $discountLineIndex,
                'assumptions' => $assumptions,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncLocked(MRF $mrf, bool $forceDryRun, bool $includePayload): array
    {
        $mrf = MRF::query()->with(['selectedVendor', 'items'])->findOrFail($mrf->id);
        if (trim((string) $mrf->signed_po_url) === '') {
            throw new QuickBooksException('Only purchase orders with a signed PO can be synced to QuickBooks.');
        }
        $vendor = $mrf->selectedVendor;
        if (! $vendor instanceof Vendor) {
            throw new QuickBooksException('This purchase order has no selected vendor.');
        }
        if ($mrf->items->isEmpty()) {
            throw new QuickBooksException('This purchase order has no line items.');
        }

        $dryRun = $forceDryRun || config('services.quickbooks.dry_run') === true;
        $canPost = ! $dryRun && config('services.quickbooks.writes_enabled') === true;
        $currency = PurchaseOrderCurrency::validate($mrf->currency);
        if ($currency === null) {
            return $this->blockedResult(
                'currency_invalid',
                'The purchase order currency is missing or unsupported. Supported currencies are NGN, USD, GBP and EUR.',
                $dryRun,
            );
        }

        $resolved = $this->resolveVendor($vendor, $currency, $mrf->id);
        if ($resolved['id'] === null) {
            return $this->blockedResult(
                $resolved['reason'] ?? 'vendor_mapping_required',
                $resolved['message'] ?? 'A verified QuickBooks vendor mapping is required before synchronisation.',
                $dryRun,
            );
        }

        $accounting = $this->quickBooks->accountingContext($mrf->id);
        $built = $this->buildPayload($mrf, [
            'vendor_id' => $resolved['id'],
            'vendor_action' => $resolved['action'],
            'po_currency' => $currency,
            'home_currency' => $accounting['home_currency'],
            'multi_currency' => $accounting['multi_currency'],
            'ap_accounts' => $accounting['ap_accounts'],
        ]);
        $payload = $built['payload'];
        $meta = $built['meta'];

        $existingId = filled($mrf->quickbooks_po_id) ? (string) $mrf->quickbooks_po_id : null;
        if ($existingId === null && isset($payload['DocNumber'])) {
            $existing = $this->quickBooks->findPurchaseOrderByDocNumber((string) $payload['DocNumber'], $mrf->id);
            if ($existing !== null && $existing['Id'] !== '') {
                $existingId = $existing['Id'];
                $meta['assumptions'][] = 'A QuickBooks purchase order with this DocNumber already exists and would be updated instead of created.';
            } else {
                $meta['assumptions'][] = 'No QuickBooks purchase order with this DocNumber exists yet.';
            }
        } elseif ($existingId === null) {
            $meta['assumptions'][] = 'DocNumber is omitted, so a timed-out create cannot be matched by DocNumber on retry.';
        }

        $operation = 'create';
        if ($existingId !== null) {
            $current = $this->quickBooks->readPurchaseOrder($existingId, $mrf->id);
            if ($current === null || $current['SyncToken'] === '') {
                throw new QuickBooksException('The linked QuickBooks purchase order could not be read for a sparse update.');
            }
            $payload['Id'] = $current['Id'];
            $payload['SyncToken'] = $current['SyncToken'];
            $payload['sparse'] = true;
            $operation = 'update';
            $meta['assumptions'][] = 'Update uses a fresh SyncToken, sparse=true, and operation=update.';
        }

        $validation = $this->validator->validate($payload, $meta, true, $mrf->id);

        $result = [
            'dry_run' => $dryRun,
            'passed' => $validation['passed'],
            'results' => $validation['results'],
            'assumptions' => $validation['assumptions'],
            'quickbooks_po_id' => $dryRun ? null : $mrf->quickbooks_po_id,
            'quickbooks_sync_status' => $dryRun ? null : $mrf->quickbooks_sync_status,
            'blocked_reason' => null,
        ];
        if ($includePayload) {
            $result['payload'] = $payload;
        }

        if (! $validation['passed']) {
            $result['blocked_reason'] = 'validation_failed';

            return $result;
        }

        if (! $canPost) {
            $result['blocked_reason'] = $dryRun ? null : 'writes_disabled';
            $result['passed'] = $dryRun;

            return $result;
        }

        $query = $operation === 'update' ? ['operation' => 'update'] : [];
        $response = $this->quickBooks->post(
            '/purchaseorder',
            $payload,
            $query,
            $mrf->id,
            $operation === 'update' ? 'update_purchase_order' : 'create_purchase_order',
        );
        $id = (string) data_get($response, 'PurchaseOrder.Id', '');
        $status = (string) data_get($response, 'PurchaseOrder.POStatus', 'Open');
        if ($id === '') {
            throw new QuickBooksException('QuickBooks did not return a purchase order id.', status: 502);
        }

        if ($resolved['action'] === 'matched' && (string) $vendor->quickbooks_vendor_id !== (string) $resolved['id']) {
            $vendor->quickbooks_vendor_id = $resolved['id'];
            $vendor->save();
        }

        $mrf->quickbooks_po_id = $id;
        $mrf->quickbooks_sync_status = $status !== '' ? $status : 'Open';
        $mrf->quickbooks_synced_at = now();
        $mrf->save();

        $result['quickbooks_po_id'] = $mrf->quickbooks_po_id;
        $result['quickbooks_sync_status'] = $mrf->quickbooks_sync_status;
        $result['passed'] = true;

        return $result;
    }

    /**
     * @return array{id: ?string, action: string, reason?: string, message?: string}
     */
    private function resolveVendor(Vendor $vendor, string $poCurrency, int $mrfId): array
    {
        $mapping = $this->suppliers->approvedMapping($vendor, $poCurrency);
        if ($mapping instanceof VendorQuickBooksMapping) {
            $verified = $this->verifyVendorCurrency((string) $mapping->quickbooks_vendor_id, $poCurrency, $mrfId);
            if ($verified['valid']) {
                return ['id' => (string) $mapping->quickbooks_vendor_id, 'action' => 'mapped'];
            }
            return [
                'id' => null,
                'action' => 'blocked',
                'reason' => $verified['reason'],
                'message' => $verified['message'],
            ];
        }

        return [
            'id' => null,
            'action' => 'blocked',
            'reason' => 'vendor_mapping_required',
            'message' => 'No verified QuickBooks vendor mapping exists. Reconcile the supplier before synchronisation.',
        ];
    }

    /**
     * @return array{valid: bool, reason: string, message: string}
     */
    private function verifyVendorCurrency(string $vendorId, string $poCurrency, int $mrfId): array
    {
        $vendor = $this->quickBooks->readVendor($vendorId, $mrfId);
        if ($vendor === null) {
            return [
                'valid' => false,
                'reason' => 'vendor_mapping_required',
                'message' => 'The configured QuickBooks vendor mapping could not be verified.',
            ];
        }

        if ($vendor['currency'] === null) {
            return [
                'valid' => false,
                'reason' => 'vendor_currency_unconfirmed',
                'message' => 'The QuickBooks vendor currency could not be confirmed.',
            ];
        }

        if ($vendor['currency'] !== $poCurrency) {
            return [
                'valid' => false,
                'reason' => 'vendor_currency_mismatch',
                'message' => "The QuickBooks vendor currency ({$vendor['currency']}) does not match the PO currency ({$poCurrency}).",
            ];
        }

        return ['valid' => true, 'reason' => '', 'message' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedResult(string $reason, string $message, bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun,
            'passed' => false,
            'results' => [[
                'check' => $reason,
                'passed' => false,
                'detail' => $message,
            ]],
            'assumptions' => [$message],
            'quickbooks_po_id' => null,
            'quickbooks_sync_status' => null,
            'blocked_reason' => $reason,
        ];
    }

    private function txnDate(MRF $mrf): string
    {
        $date = $mrf->po_signed_at ?? $mrf->po_generated_at ?? now();

        return Carbon::parse($date)->toDateString();
    }
}
