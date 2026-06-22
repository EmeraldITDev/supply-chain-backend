<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\PoNumberSequence;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Generates authoritative purchase order numbers in the canonical format:
 *
 *     PO-DDMMYY-SupplierToken-NNNN
 *
 * The supplier token and date formatting mirror the frontend helpers in
 * `src/utils/poNumber.ts` (normalizeSupplierToken / formatPoDatePart). The
 * 4-digit serial resets per supplier per calendar day and is allocated
 * atomically via the `po_number_sequences` table.
 */
class PoNumberGenerator
{
    private const TOKEN_MAX_LENGTH = 30;

    private const SERIAL_PAD = 4;

    private const FALLBACK_TOKEN = 'Vendor';

    /**
     * Strip all non-alphanumeric characters, preserve casing, cap length.
     * Matches the frontend `normalizeSupplierToken`.
     */
    public function normalizeSupplierToken(?string $name): string
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $name) ?? '';

        if ($token === '') {
            return self::FALLBACK_TOKEN;
        }

        return mb_substr($token, 0, self::TOKEN_MAX_LENGTH);
    }

    /**
     * Two-digit day + month + year, no separators (e.g. 22 Jun 2026 -> "220626").
     */
    public function formatDatePart(?\DateTimeInterface $date = null): string
    {
        $moment = $date ? Carbon::instance($date) : Carbon::now();

        return $moment->format('dmy');
    }

    public function formatSerial(int $serial): string
    {
        return str_pad((string) $serial, self::SERIAL_PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Build (without allocating a serial) the prefix for a supplier on a day.
     */
    public function prefixFor(string $supplierName, ?\DateTimeInterface $date = null): string
    {
        return 'PO-'.$this->formatDatePart($date).'-'.$this->normalizeSupplierToken($supplierName).'-';
    }

    /**
     * Allocate and return the next unique PO number for the supplier/day.
     */
    public function generate(string $supplierName, ?\DateTimeInterface $date = null): string
    {
        $datePart = $this->formatDatePart($date);
        $token = $this->normalizeSupplierToken($supplierName);
        $prefix = "PO-{$datePart}-{$token}-";
        $scopeKey = $datePart.'|'.$token;

        // Retry to guard against the (unlikely) case where a serial maps to a
        // po_number that already exists on an MRF row (e.g. legacy import).
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $serial = $this->nextSerial($scopeKey, $prefix);
            $poNumber = $prefix.$this->formatSerial($serial);

            if (! MRF::query()->where('po_number', $poNumber)->exists()) {
                return $poNumber;
            }
        }

        // Extremely defensive fallback: append a short unique suffix.
        return $prefix.$this->formatSerial($this->nextSerial($scopeKey, $prefix)).'-'.strtoupper(substr(uniqid('', true), -4));
    }

    /**
     * Atomically increment and return the serial for the scope key. The first
     * allocation seeds the counter from any pre-existing po_number rows that
     * already match the prefix (covers re-deploys / partial data).
     */
    private function nextSerial(string $scopeKey, string $prefix): int
    {
        return DB::transaction(function () use ($scopeKey, $prefix) {
            $sequence = PoNumberSequence::query()
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                try {
                    PoNumberSequence::query()->create([
                        'scope_key' => $scopeKey,
                        'last_serial' => $this->highestExistingSerial($prefix),
                    ]);
                } catch (QueryException $e) {
                    // Concurrent creator won the race; fall through to re-read.
                }

                $sequence = PoNumberSequence::query()
                    ->where('scope_key', $scopeKey)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $sequence->last_serial = (int) $sequence->last_serial + 1;
            $sequence->save();

            return (int) $sequence->last_serial;
        });
    }

    /**
     * Highest serial already used by MRF po_numbers matching the prefix.
     */
    private function highestExistingSerial(string $prefix): int
    {
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $prefix).'%';

        $numbers = MRF::query()
            ->where('po_number', 'like', $like)
            ->pluck('po_number');

        $max = 0;
        foreach ($numbers as $number) {
            if (preg_match('/-(\d+)$/', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }
}
