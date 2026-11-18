<?php

namespace App\Services;

use App\Exceptions\QuickBooksException;
use App\Models\MRF;
use App\Models\Vendor;

class QuickBooksPullService
{
    public function __construct(private QuickBooksService $quickBooks) {}

    /**
     * @return array<string, mixed>
     */
    public function pullVendors(): array
    {
        $quickbooksVendors = $this->quickbooksVendors();
        $scmVendors = Vendor::query()->orderBy('id')->get([
            'id',
            'name',
            'email',
            'contact_person_email',
            'quickbooks_vendor_id',
        ]);

        $matched = [];
        $usedScm = [];
        $matchedQuickBooks = [];

        foreach ($quickbooksVendors as $qb) {
            $match = $this->matchVendor($scmVendors, $qb, $usedScm);
            if ($match === null) {
                continue;
            }

            $scm = $match['vendor'] ?? null;
            if ($scm instanceof Vendor) {
                $usedScm[] = $scm->id;
            }
            $matchedQuickBooks[] = $qb['id'];
            $row = [
                'scm_vendor_id' => $scm?->id,
                'scm_vendor_name' => $scm?->name,
                'quickbooks_vendor_id' => $qb['id'],
                'quickbooks_name' => $qb['display_name'] ?? $qb['company_name'],
                'quickbooks_company_name' => $qb['company_name'],
                'quickbooks_email' => $qb['email'],
                'currency' => $qb['currency'],
                'match_method' => $match['method'],
                'match_status' => $match['status'],
                'candidate_scm_vendor_ids' => $match['candidate_ids'],
                'conflict' => $match['status'] !== 'matched',
            ];

            $matched[] = $row;
        }

        $unmatched = [];
        foreach ($quickbooksVendors as $qb) {
            if (! in_array($qb['id'], $matchedQuickBooks, true)) {
                $unmatched[] = $qb;
            }
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pullPurchaseOrders(): array
    {
        $updated = [];
        $mrfs = MRF::query()->whereNotNull('quickbooks_po_id')->where('quickbooks_po_id', '!=', '')->get();
        $linked = [];

        foreach ($mrfs as $mrf) {
            $linked[] = (string) $mrf->quickbooks_po_id;
            try {
                $po = $this->quickBooks->readPurchaseOrder((string) $mrf->quickbooks_po_id, $mrf->id);
            } catch (QuickBooksException $e) {
                $updated[] = [
                    'mrf_id' => $mrf->id,
                    'quickbooks_po_id' => $mrf->quickbooks_po_id,
                    'updated' => false,
                    'fault_code' => $e->faultCode,
                ];
                continue;
            }

            if ($po === null) {
                $updated[] = [
                    'mrf_id' => $mrf->id,
                    'quickbooks_po_id' => $mrf->quickbooks_po_id,
                    'updated' => false,
                    'fault_code' => '610',
                ];
                continue;
            }

            $status = $po['POStatus'] !== '' ? $po['POStatus'] : null;
            $mrf->quickbooks_sync_status = $status;
            $mrf->quickbooks_synced_at = now();
            $mrf->save();
            $updated[] = [
                'mrf_id' => $mrf->id,
                'quickbooks_po_id' => $mrf->quickbooks_po_id,
                'quickbooks_sync_status' => $status,
                'updated' => true,
            ];
        }

        $unlinked = [];
        foreach ($this->quickbooksPurchaseOrders() as $po) {
            if (! in_array($po['id'], $linked, true)) {
                $unlinked[] = $po;
            }
        }

        return [
            'updated' => $updated,
            'unlinked' => $unlinked,
        ];
    }

    /**
     * @return list<array{id: string, display_name: ?string, company_name: ?string, email: ?string, currency: ?string}>
     */
    public function quickbooksVendors(): array
    {
        $rows = [];
        $start = 1;
        do {
            $result = $this->quickBooks->query(
                "select Id, DisplayName, CompanyName, PrimaryEmailAddr, CurrencyRef from Vendor startposition {$start} maxresults 100",
                null,
                'pull_vendors',
            );
            $batch = QuickBooksService::normalizeList(data_get($result, 'QueryResponse.Vendor'));
            foreach ($batch as $row) {
                $id = isset($row['Id']) ? (string) $row['Id'] : '';
                if ($id === '') {
                    continue;
                }
                $rows[] = [
                    'id' => $id,
                    'display_name' => isset($row['DisplayName']) ? (string) $row['DisplayName'] : null,
                    'company_name' => isset($row['CompanyName']) ? (string) $row['CompanyName'] : null,
                    'email' => data_get($row, 'PrimaryEmailAddr.Address'),
                    'currency' => $this->currencyFromVendor($row),
                ];
            }
            $start += count($batch);
        } while (count($batch) === 100 && $start < 5000);

        return $rows;
    }

    /**
     * @return list<array{id: string, doc_number: ?string, po_status: ?string}>
     */
    private function quickbooksPurchaseOrders(): array
    {
        $rows = [];
        $start = 1;
        do {
            $result = $this->quickBooks->query(
                "select Id, DocNumber, POStatus from PurchaseOrder startposition {$start} maxresults 100",
                null,
                'pull_purchase_orders',
            );
            $batch = QuickBooksService::normalizeList(data_get($result, 'QueryResponse.PurchaseOrder'));
            foreach ($batch as $row) {
                $id = isset($row['Id']) ? (string) $row['Id'] : '';
                if ($id === '') {
                    continue;
                }
                $rows[] = [
                    'id' => $id,
                    'doc_number' => isset($row['DocNumber']) ? (string) $row['DocNumber'] : null,
                    'po_status' => isset($row['POStatus']) ? (string) $row['POStatus'] : null,
                ];
            }
            $start += count($batch);
        } while (count($batch) === 100 && $start < 1000);

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Vendor>  $scmVendors
     * @param  array{id: string, display_name: ?string, company_name: ?string, email: ?string, currency: ?string}  $qb
     * @param  list<int>  $usedScm
     * @return array{vendor: ?Vendor, method: string, status: string, candidate_ids: list<int>}|null
     */
    private function matchVendor($scmVendors, array $qb, array $usedScm): ?array
    {
        $email = Vendor::normalizeEmail($qb['email'] ?? null);
        $name = Vendor::normalizeName($qb['company_name'] ?: $qb['display_name']);
        $emailMatches = $email === '' ? collect() : $scmVendors->filter(function (Vendor $vendor) use ($email, $usedScm) {
            return ! in_array($vendor->id, $usedScm, true)
                && (Vendor::normalizeEmail($vendor->email) === $email
                    || Vendor::normalizeEmail($vendor->contact_person_email) === $email);
        });
        $nameMatches = $name === '' ? collect() : $scmVendors->filter(function (Vendor $vendor) use ($name, $usedScm) {
            return ! in_array($vendor->id, $usedScm, true)
                && Vendor::normalizeName($vendor->name) === $name;
        });
        $candidateIds = $emailMatches->merge($nameMatches)->pluck('id')->unique()->values()->all();

        if ($candidateIds === []) {
            return null;
        }

        $sameIdentity = $emailMatches->pluck('id')->intersect($nameMatches->pluck('id'))->count() > 0;
        $status = count($candidateIds) === 1 && ($emailMatches->isEmpty() || $nameMatches->isEmpty() || $sameIdentity)
            ? 'matched'
            : 'review_required';
        $vendor = $status === 'matched' ? $scmVendors->firstWhere('id', $candidateIds[0]) : null;

        return [
            'vendor' => $vendor,
            'method' => $emailMatches->isNotEmpty() && $nameMatches->isNotEmpty() ? 'email_and_name' : ($emailMatches->isNotEmpty() ? 'email' : 'name'),
            'status' => $status,
            'candidate_ids' => array_map('intval', $candidateIds),
        ];
    }

    private function currencyFromVendor(array $row): ?string
    {
        $currency = data_get($row, 'CurrencyRef.value') ?? data_get($row, 'CurrencyRef');

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    }
}
