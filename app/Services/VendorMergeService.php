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
    public function findDuplicateGroups(?string $nameFilter = null, bool $activeOnly = true): Collection
    {
        $query = Vendor::query()->orderBy('id');

        if ($activeOnly) {
            $query->where('status', '!=', 'Inactive');
        }

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
            fn (Vendor $vendor) => $this->hasRealEmail($vendor) ? 0 : 1,
            fn (Vendor $vendor) => $this->hasPortalUser($vendor) ? 0 : 1,
            fn (Vendor $vendor) => $this->vendorCodeNumber($vendor->vendor_id),
            fn (Vendor $vendor) => $vendor->id,
        ])->first();
    }

    /**
     * @return array{canonical: Vendor, merged: list<string>, skipped: list<string>, already_merged: list<string>}
     */
    public function mergeGroup(Vendor $canonical, Collection $duplicates, bool $dryRun = true): array
    {
        $merged = [];
        $skipped = [];
        $alreadyMerged = [];

        foreach ($duplicates as $duplicate) {
            if ($duplicate->id === $canonical->id) {
                continue;
            }

            if ($this->isAlreadyMergedInto($duplicate, $canonical)) {
                $alreadyMerged[] = $duplicate->vendor_id;

                continue;
            }

            if ($dryRun) {
                $merged[] = $duplicate->vendor_id;

                continue;
            }

            try {
                DB::transaction(function () use ($canonical, $duplicate, &$merged, &$skipped) {
                    $this->reassignReferences($canonical, $duplicate);
                    $this->deactivateDuplicate($canonical, $duplicate);
                    $merged[] = $duplicate->vendor_id;
                });
            } catch (\Throwable $e) {
                $skipped[] = $duplicate->vendor_id.' ('.$e->getMessage().')';
            }
        }

        if (! $dryRun) {
            $this->fillBlankFieldsFromDuplicates($canonical, $duplicates);
        }

        return [
            'canonical' => $canonical->fresh(),
            'merged' => $merged,
            'skipped' => $skipped,
            'already_merged' => $alreadyMerged,
        ];
    }

    /**
     * @return array{deleted: list<string>, skipped: list<string>}
     */
    public function purgeInactiveMergedDuplicates(bool $dryRun = true): array
    {
        $deleted = [];
        $skipped = [];

        $candidates = Vendor::query()
            ->where('status', 'Inactive')
            ->where('notes', 'like', '%Merged into V%')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $vendor) {
            $refs = $this->countReferences($vendor->id);
            if ($refs > 0) {
                $skipped[] = $vendor->vendor_id.' ('.$refs.' reference(s) remain)';

                continue;
            }

            if ($dryRun) {
                $deleted[] = $vendor->vendor_id;

                continue;
            }

            try {
                $vendor->delete();
                $deleted[] = $vendor->vendor_id;
            } catch (\Throwable $e) {
                $skipped[] = $vendor->vendor_id.' ('.$e->getMessage().')';
            }
        }

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{canonical: Vendor, merged: list<string>, skipped: list<string>, already_merged: list<string>}
     */
    public function mergeIntoCanonical(string $canonicalCode, array $duplicateCodes, bool $dryRun = true): array
    {
        $canonical = Vendor::query()->where('vendor_id', $canonicalCode)->firstOrFail();
        $duplicates = Vendor::query()->whereIn('vendor_id', $duplicateCodes)->get();

        return $this->mergeGroup($canonical, $duplicates->push($canonical), $dryRun);
    }

    public function countReferences(int $vendorId): int
    {
        $total = 0;

        foreach ($this->referenceColumns() as $table => $column) {
            $total += (int) DB::table($table)->where($column, $vendorId)->count();
        }

        if (Schema::hasColumn('users', 'vendor_id')) {
            $total += (int) User::query()->where('vendor_id', $vendorId)->count();
        }

        return $total;
    }

    private function isAlreadyMergedInto(Vendor $duplicate, Vendor $canonical): bool
    {
        if ($duplicate->status !== 'Inactive') {
            return false;
        }

        $notes = (string) $duplicate->notes;

        return str_contains($notes, 'Merged into '.$canonical->vendor_id);
    }

    private function hasRealEmail(Vendor $vendor): bool
    {
        $email = trim((string) $vendor->email);

        return $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && ! str_contains(strtolower($email), '@supplier.placeholder');
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

    /**
     * @return array<string, string>
     */
    private function referenceColumns(): array
    {
        return [
            'price_comparisons' => 'vendor_id',
            'quotations' => 'vendor_id',
            'vendor_registrations' => 'vendor_id',
            'vendor_ratings' => 'vendor_id',
            'procurement_documents' => 'vendor_id',
            'm_r_f_s' => 'selected_vendor_id',
            'r_f_q_s' => 'selected_vendor_id',
            'logistics_trips' => 'vendor_id',
            'logistics_vehicles' => 'vendor_id',
            'logistics_material_movements' => 'vendor_id',
            'logistics_material_jccs' => 'vendor_id',
            'job_completion_certificates' => 'vendor_id',
            'trip_vendor_submissions' => 'vendor_id',
            'rfq_vendors' => 'vendor_id',
        ];
    }

    private function reassignReferences(Vendor $canonical, Vendor $duplicate): void
    {
        $fromId = $duplicate->id;
        $toId = $canonical->id;

        foreach ($this->referenceColumns() as $table => $column) {
            if ($table === 'rfq_vendors') {
                $this->mergeRfqVendorRows($fromId, $toId);

                continue;
            }

            if ($table === 'logistics_trips' && Schema::hasColumn('logistics_trips', 'selected_vendor_id')) {
                $this->updateColumn('logistics_trips', 'selected_vendor_id', $fromId, $toId);
            }

            $this->updateColumn($table, $column, $fromId, $toId);
        }

        if (Schema::hasColumn('users', 'vendor_id')) {
            $this->updateColumn('users', 'vendor_id', $fromId, $toId);
        }
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

        foreach (['phone', 'address', 'contact_person', 'contact_person_email', 'tax_id', 'website', 'email'] as $field) {
            if ($field === 'email' && $this->hasRealEmail($canonical)) {
                continue;
            }

            if (trim((string) ($canonical->{$field} ?? '')) !== '') {
                continue;
            }

            foreach ($group as $vendor) {
                if ($field === 'email' && ! $this->hasRealEmail($vendor)) {
                    continue;
                }

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
