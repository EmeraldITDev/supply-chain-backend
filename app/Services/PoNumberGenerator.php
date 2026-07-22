<?php

namespace App\Services;

use App\Models\MRF;
use App\Models\PoNumberSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
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
     * Accepts a Vendor model or string name to guarantee consistency.
     */
    public function normalizeSupplierToken(Vendor|string|null $supplier): string
    {
        $name = $supplier instanceof Vendor ? $supplier->name : $supplier;
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
    public function prefixFor(Vendor|string $supplier, ?\DateTimeInterface $date = null): string
    {
        return 'PO-' . $this->formatDatePart($date) . '-' . $this->normalizeSupplierToken($supplier) . '-';
    }

    /**
     * Allocate and return the next unique PO number for the supplier/day.
     */
    public function generate(Vendor|string $supplier, ?\DateTimeInterface $date = null): string
    {
        $datePart = $this->formatDatePart($date);
        $token = $this->normalizeSupplierToken($supplier);
        $prefix = "PO-{$datePart}-{$token}-";
        
        // Scope by vendor ID when a Vendor object is passed to prevent string-drift sequence fragmentation
        $scopeKey = $supplier instanceof Vendor 
            ? "{$datePart}|ID:{$supplier->id}|{$token}" 
            : "{$datePart}|{$token}";

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $serial = $this->nextSerial($scopeKey, $prefix);
            $poNumber = $prefix . $this->formatSerial($serial);

            if (! $this->poNumberExists($poNumber)) {
                return $poNumber;
            }
        }

        // Extremely defensive fallback: append a short unique suffix.
        return $prefix . $this->formatSerial($this->nextSerial($scopeKey, $prefix)) . '-' . strtoupper(substr(uniqid('', true), -4));
    }

    /**
     * Atomically increment and return the serial for the scope key.
     * PostgreSQL-safe: Avoids try/catch QueryException blocks that permanently abort active transactions.
     */
    private function nextSerial(string $scopeKey, string $prefix): int
    {
        return DB::transaction(function () use ($scopeKey, $prefix) {
            $sequence = PoNumberSequence::query()
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                // Use firstOrCreate to prevent PostgreSQL transaction aborts during concurrent inserts
                PoNumberSequence::query()->firstOrCreate(
                    ['scope_key' => $scopeKey],
                    ['last_serial' => $this->highestExistingSerial($prefix)]
                );

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
     * Check across all possible order tables to ensure the PO number does not already exist.
     */
    private function poNumberExists(string $poNumber): bool
    {
        if (MRF::query()->where('po_number', $poNumber)->exists()) {
            return true;
        }

        // Safely check dedicated PO, SRF, or Logistics tables if they exist in your database schema
        foreach (['purchase_orders', 's_r_f_s', 'logistics_requests'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('po_number', $poNumber)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Highest serial already used across all relevant tables matching the prefix.
     */
    private function highestExistingSerial(string $prefix): int
    {
        $like = str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '%';
        $max = 0;

        $tables = ['m_r_f_s'];
        foreach (['purchase_orders', 's_r_f_s', 'logistics_requests'] as $table) {
            if (Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }

        foreach ($tables as $table) {
            $numbers = DB::table($table)
                ->where('po_number', 'like', $like)
                ->pluck('po_number');

            foreach ($numbers as $number) {
                if (preg_match('/-(\d+)$/', (string) $number, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
        }

        return $max;
    }
}