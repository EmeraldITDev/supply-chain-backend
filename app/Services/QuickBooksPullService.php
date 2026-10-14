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

            $scm = $match['vendor'];
            $usedScm[] = $scm->id;
            $matchedQuickBooks[] = $qb['id'];
            $row = [
                'scm_vendor_id' => $scm->id,
                'scm_vendor_name' => $scm->name,
                'quickbooks_vendor_id' => (string) ($scm->quickbooks_vendor_id ?: $qb['id']),
                'quickbooks_name' => $qb['display_name'] ?? $qb['company_name'],
                'match_method' => $match['method'],
                'conflict' => false,
            ];
            if ((string) $scm->quickbooks_vendor_id === '') {
                $scm->quickbooks_vendor_id = $qb['id'];
                $scm->save();
                $row['quickbooks_vendor_id'] = $qb['id'];
            } elseif ((string) $scm->quickbooks_vendor_id !== $qb['id']) {
                $row['quickbooks_vendor_id'] = (string) $scm->quickbooks_vendor_id;
                $row['conflict'] = true;
                $matched[] = $row;
                continue;
            }

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
     * @return list<array{id: string, display_name: ?string, company_name: ?string, email: ?string}>
     */
    private function quickbooksVendors(): array
    {
        $rows = [];
        $start = 1;
        do {
            $result = $this->quickBooks->query(
                "select Id, DisplayName, CompanyName, PrimaryEmailAddr from Vendor startposition {$start} maxresults 100",
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
     * @param  array{id: string, display_name: ?string, company_name: ?string, email: ?string}  $qb
     * @param  list<int>  $usedScm
     * @return array{vendor: Vendor, method: 'email'|'name'}|null
     */
    private function matchVendor($scmVendors, array $qb, array $usedScm): ?array
    {
        $email = Vendor::normalizeEmail($qb['email'] ?? null);
        if ($email !== '') {
            $byEmail = $scmVendors->first(function (Vendor $vendor) use ($email, $usedScm) {
                if (in_array($vendor->id, $usedScm, true)) {
                    return false;
                }

                return Vendor::normalizeEmail($vendor->email) === $email
                    || Vendor::normalizeEmail($vendor->contact_person_email) === $email;
            });
            if ($byEmail instanceof Vendor) {
                return ['vendor' => $byEmail, 'method' => 'email'];
            }
        }

        foreach ([$qb['display_name'] ?? null, $qb['company_name'] ?? null] as $candidate) {
            $name = Vendor::normalizeName($candidate);
            if ($name === '') {
                continue;
            }
            $byName = $scmVendors->first(function (Vendor $vendor) use ($name, $usedScm) {
                return ! in_array($vendor->id, $usedScm, true)
                    && Vendor::normalizeName($vendor->name) === $name;
            });
            if ($byName instanceof Vendor) {
                return ['vendor' => $byName, 'method' => 'name'];
            }
        }

        return null;
    }
}
