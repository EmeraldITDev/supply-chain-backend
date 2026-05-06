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

        // Keep the real contract name in IDs (human-readable), sanitized.
        return $this->sanitizeSegment($raw, 'EMERALD');
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

        if ($row) {
            return $this->sanitizeSegment((string) $row->code, 'GEN');
        }

        // Smarter fallback: derive short code from provided label.
        return $this->inferCodeFromLabel($departmentName, 2, 4, 'GEN');
    }

    private function lookupCategoryCode(string $type, ?string $categoryName): string
    {
        $categoryName = trim((string) $categoryName);
        if ($categoryName === '') {
            return 'OTH';
        }

        $normalized = $this->normalizeLookupKey($categoryName);

        $row = DB::table('category_codes')
            ->where('request_type', strtoupper($type))
            ->select(['code', 'category_name'])
            ->get()
            ->first(function ($r) use ($normalized) {
                return $this->normalizeLookupKey($r->category_name) === $normalized;
            });

        if ($row) {
            return $this->sanitizeSegment((string) $row->code, 'OTH');
        }

        // Smarter fallback: derive concise category abbreviation.
        return $this->inferCodeFromLabel($categoryName, 3, 4, 'OTH');
    }

    private function normalizeLookupKey(string $value): string
    {
        $value = Str::of($value)->lower()->trim();
        $value = (string) preg_replace('/[^a-z0-9\s]/', ' ', (string) $value);
        $value = (string) preg_replace('/\s+/', ' ', (string) $value);
        return $value;
    }

    private function inferCodeFromLabel(string $label, int $minLen, int $maxLen, string $fallback): string
    {
        $normalized = $this->normalizeLookupKey($label);
        if ($normalized === '') {
            return $fallback;
        }

        $words = array_values(array_filter(explode(' ', $normalized)));
        if (count($words) >= 2) {
            $acronym = '';
            foreach ($words as $word) {
                $acronym .= strtoupper(substr($word, 0, 1));
            }
            if (strlen($acronym) >= $minLen) {
                return substr($acronym, 0, $maxLen);
            }
        }

        $flat = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($normalized)) ?? '');
        if ($flat === '') {
            return $fallback;
        }

        return substr($flat, 0, $maxLen);
    }

    private function sanitizeSegment(string $raw, string $fallback): string
    {
        $segment = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($raw))) ?? '');
        if ($segment === '') {
            return $fallback;
        }

        return substr($segment, 0, 12);
    }
}

