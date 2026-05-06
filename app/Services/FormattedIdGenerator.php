<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FormattedIdGenerator
{
    /**
     * Generate formatted ID:
     * {TYPE}-{CONTRACT}-{DEPT}-{CAT}-{YEAR}-{SEQ}
     *
     * Context keys:
     * - contract_type: string|null
     * - department: string|null (department name, not code)
     * - category: string|null (category name, not code)
     * - created_at: Carbon|null
     */
    public function generate(string $type, array $context): string
    {
        $type = strtoupper(trim($type));

        $contract = $this->normalizeContractType($context['contract_type'] ?? null);
        $deptCode = $this->lookupDepartmentCode($context['department'] ?? null);
        $catCode = $this->lookupCategoryCode($type, $context['category'] ?? null);

        /** @var Carbon $createdAt */
        $createdAt = $context['created_at'] ?? null;
        $createdAt = $createdAt instanceof Carbon ? $createdAt : now();

        $year = $createdAt->format('Y');

        // Recommended scope: per (TYPE, YEAR) only (avoid fragmentation)
        $scope = "{$type}-{$year}";

        $seq = DB::transaction(function () use ($scope) {
            $row = DB::table('id_sequences')->where('scope', $scope)->lockForUpdate()->first();

            if (!$row) {
                DB::table('id_sequences')->insert([
                    'scope' => $scope,
                    'last_seq' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                return 1;
            }

            $next = ((int) $row->last_seq) + 1;
            DB::table('id_sequences')->where('scope', $scope)->update([
                'last_seq' => $next,
                'updated_at' => now(),
            ]);
            return $next;
        });

        $seqStr = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        return "{$type}-{$contract}-{$deptCode}-{$catCode}-{$year}-{$seqStr}";
    }

    private function normalizeContractType(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 'EMERALD';
        }

        $rawLower = strtolower($raw);
        if ($rawLower === 'emerald') {
            return 'EMERALD';
        }

        // Spec only calls out EMERALD and NONEMERALD.
        return 'NONEMERALD';
    }

    private function lookupDepartmentCode(?string $departmentName): string
    {
        $departmentName = trim((string) $departmentName);
        if ($departmentName === '') {
            return 'GEN';
        }

        $normalized = $this->normalizeLookupKey($departmentName);

        $row = DB::table('department_codes')
            ->select(['code', 'department_name'])
            ->get()
            ->first(function ($r) use ($normalized) {
                return $this->normalizeLookupKey($r->department_name) === $normalized;
            });

        return $row ? strtoupper($row->code) : 'GEN';
    }

    private function lookupCategoryCode(string $type, ?string $categoryName): string
    {
        $categoryName = trim((string) $categoryName);
        if ($categoryName === '') {
            return 'GEN';
        }

        $normalized = $this->normalizeLookupKey($categoryName);

        $row = DB::table('category_codes')
            ->where('request_type', strtoupper($type))
            ->select(['code', 'category_name'])
            ->get()
            ->first(function ($r) use ($normalized) {
                return $this->normalizeLookupKey($r->category_name) === $normalized;
            });

        return $row ? strtoupper($row->code) : 'GEN';
    }

    private function normalizeLookupKey(string $value): string
    {
        $value = Str::of($value)->lower()->trim();
        $value = (string) preg_replace('/\s+/', ' ', (string) $value);
        return $value;
    }
}

