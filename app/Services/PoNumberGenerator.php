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
     * Intelligently extracts the clean name whether given a Model, Array, JSON, or ID code.
     */
    public function normalizeSupplierToken(mixed $supplier): string
    {
        $name = null;

        // 1. If passed an Eloquent Vendor Model
        if ($supplier instanceof Vendor) {
            $name = $supplier->name ?: $supplier->code;
        } 
        // 2. If passed an array (e.g., ['id' => 211, 'vendor_id' => 'V158', 'name' => 'FEMBOSCO...'])
        elseif (is_array($supplier) || $supplier instanceof \ArrayAccess) {
            $name = $supplier['name'] ?? $supplier['company_name'] ?? $supplier['vendor_name'] ?? null;
        } 
        // 3. If passed a JSON string or serialized object string
        elseif (is_string($supplier) && (str_starts_with(trim($supplier), '{') || str_starts_with(trim($supplier), '['))) {
            $decoded = json_decode($supplier, true);
            if (is_array($decoded)) {
                $name = $decoded['name'] ?? $decoded['company_name'] ?? $decoded['vendor_name'] ?? null;
            }
        } 
        // 4. If passed a standard PHP object with a name property
        elseif (is_object($supplier) && isset($supplier->name)) {
            $name = $supplier->name;
        } 
        // 5. If passed a string or number (like "FEMBOSCO" or "V158")
        elseif (is_scalar($supplier)) {
            $name = trim((string) $supplier);
            
            // If it passed a vendor code or ID like "V158" or "14", look up the real company name from DB!
            if (preg_match('/^(V?\d+)$/i', $name)) {
                $dbVendor = Vendor::where('code', $name)
                    ->orWhere('vendor_id', $name)
                    ->orWhere('id', $name)
                    ->first();
                    
                if ($dbVendor && !empty($dbVendor->name)) {
                    $name = $dbVendor->name;
                }
            }
        }

        // Fallback if name extraction failed
        $name = $name ?: (is_scalar($supplier) ? (string) $supplier : '');
        
        // Strip non-alphanumeric characters for clean PO numbering
        $token = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '';

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
    public function prefixFor(mixed $supplier, ?\DateTimeInterface $date = null): string
    {
        return 'PO-' . $this->formatDatePart($date) . '-' . $this->normalizeSupplierToken($supplier) . '-';
    }

    /**
     * Allocate and return the next unique PO number for the supplier/day.
     */
    public function generate(mixed $supplier, ?\DateTimeInterface $date = null): string
    {
        $datePart = $this->formatDatePart($date);
        $token = $this->normalizeSupplierToken($supplier);
        $prefix = "PO-{$datePart}-{$token}-";
        
        // Scope cleanly to avoid collisions
        $vendorId = $supplier instanceof Vendor ? $supplier->id : (is_array($supplier) ? ($supplier['id'] ?? null) : null);
        $scopeKey = $vendorId ? "{$datePart}|ID:{$vendorId}|{$token}" : "{$datePart}|{$token}";

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $serial = $this->nextSerial($scopeKey, $prefix);
            $poNumber = $prefix . $this->formatSerial($serial);

            if (! $this->poNumberExists($poNumber)) {
                return $poNumber;
            }
        }

        return $prefix . $this->formatSerial($this->nextSerial($scopeKey, $prefix)) . '-' . strtoupper(substr(uniqid('', true), -4));
    }

    /**
     * Atomically increment and return the serial for the scope key.
     */
    private function nextSerial(string $scopeKey, string $prefix): int
    {
        return DB::transaction(function () use ($scopeKey, $prefix) {
            $sequence = PoNumberSequence::query()
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
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
     * Check across all possible order tables safely by verifying the column exists first.
     */
    private function poNumberExists(string $poNumber): bool
    {
        if (MRF::query()->where('po_number', $poNumber)->exists()) {
            return true;
        }

        // Only query tables if they actually possess the po_number column (Prevented SQL crash!)
        foreach (['purchase_orders', 's_r_f_s', 'logistics_requests'] as $table) {
            if (Schema::hasColumn($table, 'po_number') && DB::table($table)->where('po_number', $poNumber)->exists()) {
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
            if (Schema::hasColumn($table, 'po_number')) {
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