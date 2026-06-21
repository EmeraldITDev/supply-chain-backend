<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VendorMergeService
{
    /**
     * @return Collection<string, Collection<int, Vendor>>
     */
    public function findDuplicateGroups(?string $nameFilter = null): Collection
    {
        $query = Vendor::query()->orderBy('id');

        if ($nameFilter !== null && trim($nameFilter) !== '') {
            $needle = '%'.mb_strtolower(trim($nameFilter)).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$needle]);
        }

        return $query->get()
            ->groupBy(fn (Vendor $vendor) => Vendor::normalizeName($vendor->name))
            ->filter(fn (Collection $group, string $key) => $key !== '' && $group->count() > 1);
    }

    public function pickCanonical(Collection $group): Vendor
    {
        return $group->sortBy([
            fn (Vendor $vendor) => $this->hasPortalUser($vendor) ? 0 : 1,
            fn (Vendor $vendor) => $this->vendorCodeNumber($vendor->vendor_id),
            fn (Vendor $vendor) => $vendor->id,
        ])->first();
    }

    /**
     * @return array{canonical: Vendor, merged: list<string>, skipped: list<string>}
     */
    public function mergeGroup(Vendor $canonical, Collection $duplicates, bool $dryRun = true): array
    {
        $merged = [];
        $skipped = [];

        foreach ($duplicates as $duplicate) {
            if ($duplicate->id === $canonical->id) {
                continue;
            }

            if ($dryRun) {
                $merged[] = $duplicate->vendor_id;

                continue;
            }

            DB::transaction(function () use ($canonical, $duplicate, &$merged, &$skipped) {
                try {
                    $this->reassignReferences($canonical, $duplicate);
                    $this->deactivateDuplicate($canonical, $duplicate);
                    $merged[] = $duplicate->vendor_id;
                } catch (\Throwable $e) {
                    $skipped[] = $duplicate->vendor_id.' ('.$e->getMessage().')';
                }
            });
        }

        if (! $dryRun) {
            $this->fillBlankFieldsFromDuplicates($canonical, $duplicates);
        }

        return [
            'canonical' => $canonical->fresh(),
            'merged' => $merged,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{canonical: Vendor, merged: list<string>, skipped: list<string>}
     */
    public function mergeIntoCanonical(string $canonicalCode, array $duplicateCodes, bool $dryRun = true): array
    {
        $canonical = Vendor::query()->where('vendor_id', $canonicalCode)->firstOrFail();
        $duplicates = Vendor::query()->whereIn('vendor_id', $duplicateCodes)->get();

        return $this->mergeGroup($canonical, $duplicates->push($canonical), $dryRun);
    }

    private function hasPortalUser(Vendor $vendor): bool
    {
        if (Schema::hasColumn('users', 'vendor_id')) {
            if (User::query()->where('vendor_id', $vendor->id)->exists()) {
                return true;
            }
        }

        return User::findByEmailCaseInsensitive((string) $vendor->email) !== null;
    }

    private function vendorCodeNumber(?string $vendorCode): int
    {
        if ($vendorCode && preg_match('/(\d+)/', $vendorCode, $matches)) {
            return (int) $matches[1];
        }

        return PHP_INT_MAX;
    }

    private function reassignReferences(Vendor $canonical, Vendor $duplicate): void
    {
        $fromId = $duplicate->id;
        $toId = $canonical->id;

        $this->updateColumn('price_comparisons', 'vendor_id', $fromId, $toId);
        $this->updateColumn('quotations', 'vendor_id', $fromId, $toId);
        $this->updateColumn('vendor_registrations', 'vendor_id', $fromId, $toId);
        $this->updateColumn('vendor_ratings', 'vendor_id', $fromId, $toId);
        $this->updateColumn('procurement_documents', 'vendor_id', $fromId, $toId);
        $this->updateColumn('m_r_f_s', 'selected_vendor_id', $fromId, $toId);
        $this->updateColumn('r_f_q_s', 'selected_vendor_id', $fromId, $toId);
        $this->updateColumn('users', 'vendor_id', $fromId, $toId);
        $this->updateColumn('logistics_trips', 'vendor_id', $fromId, $toId);
        $this->updateColumn('logistics_trips', 'selected_vendor_id', $fromId, $toId);
        $this->updateColumn('logistics_vehicles', 'vendor_id', $fromId, $toId);
        $this->updateColumn('logistics_material_movements', 'vendor_id', $fromId, $toId);
        $this->updateColumn('logistics_material_jccs', 'vendor_id', $fromId, $toId);
        $this->updateColumn('job_completion_certificates', 'vendor_id', $fromId, $toId);
        $this->updateColumn('trip_vendor_submissions', 'vendor_id', $fromId, $toId);

        $this->mergeRfqVendorRows($fromId, $toId);
    }

    private function updateColumn(string $table, string $column, int $fromId, int $toId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $fromId)->update([$column => $toId]);
    }

    private function mergeRfqVendorRows(int $fromId, int $toId): void
    {
        if (! Schema::hasTable('rfq_vendors')) {
            return;
        }

        $rows = DB::table('rfq_vendors')->where('vendor_id', $fromId)->get();

        foreach ($rows as $row) {
            $exists = DB::table('rfq_vendors')
                ->where('rfq_id', $row->rfq_id)
                ->where('vendor_id', $toId)
                ->exists();

            if ($exists) {
                DB::table('rfq_vendors')->where('id', $row->id)->delete();
            } else {
                DB::table('rfq_vendors')->where('id', $row->id)->update(['vendor_id' => $toId]);
            }
        }
    }

    private function deactivateDuplicate(Vendor $canonical, Vendor $duplicate): void
    {
        $duplicate->update([
            'status' => 'Inactive',
            'notes' => trim(((string) $duplicate->notes).' Merged into '.$canonical->vendor_id.' on '.now()->toDateString()),
        ]);

        $duplicateUser = User::query()->where('vendor_id', $duplicate->id)->first();
        if ($duplicateUser && ! $this->hasPortalUser($canonical)) {
            $duplicateUser->update(['vendor_id' => $canonical->id]);
        } elseif ($duplicateUser) {
            $duplicateUser->tokens()->delete();
            $duplicateUser->update([
                'supply_chain_role' => null,
                'vendor_id' => null,
            ]);
        }
    }

    /**
     * @param  Collection<int, Vendor>  $group
     */
    private function fillBlankFieldsFromDuplicates(Vendor $canonical, Collection $group): void
    {
        $updates = [];

        foreach (['phone', 'address', 'contact_person', 'contact_person_email', 'tax_id', 'website'] as $field) {
            if (trim((string) ($canonical->{$field} ?? '')) !== '') {
                continue;
            }

            foreach ($group as $vendor) {
                $value = trim((string) ($vendor->{$field} ?? ''));
                if ($value !== '') {
                    $updates[$field] = $value;
                    break;
                }
            }
        }

        if ($updates !== []) {
            $canonical->update($updates);
        }
    }
}
