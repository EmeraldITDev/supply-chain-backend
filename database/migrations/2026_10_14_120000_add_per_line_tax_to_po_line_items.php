<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line-item VAT on POs.
 *
 * - price_comparisons.tax_rate / mrf_line_items.tax_rate: NULL means "use the default"
 *   (7.5% for per-line POs, the MRF's global tax_rate for legacy POs).
 * - m_r_f_s.tax_mode: 'per_line' for POs saved with per-line VAT; NULL for legacy
 *   POs that only carry the global m_r_f_s.tax_rate / tax_amount.
 *
 * Additive only — no backfill, so existing POs keep their current totals.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumn('price_comparisons', 'tax_rate', 'DECIMAL(5,2) NULL');
        $this->addColumn('mrf_line_items', 'tax_rate', 'DECIMAL(5,2) NULL');
        $this->addColumn('m_r_f_s', 'tax_mode', 'VARCHAR(16) NULL');

        $this->forgetColumnCache();
    }

    public function down(): void
    {
        foreach ([
            'price_comparisons' => 'tax_rate',
            'mrf_line_items' => 'tax_rate',
            'm_r_f_s' => 'tax_mode',
        ] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }

        $this->forgetColumnCache();
    }

    private function forgetColumnCache(): void
    {
        foreach (['m_r_f_s', 'price_comparisons', 'mrf_line_items'] as $table) {
            \App\Support\TableColumnCache::forget($table);
        }
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} {$definition}");

            return;
        }

        DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
};
