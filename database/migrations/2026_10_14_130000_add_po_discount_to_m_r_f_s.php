<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO-level discount, taken before VAT.
 *
 * - discount_type: 'percentage' (of subtotal) or 'amount' (fixed, PO currency); NULL = none.
 * - discount_value: the entered percentage or amount.
 * - discount_amount: the resolved money discount saved when totals are computed.
 *
 * Additive only — existing POs have no discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumn('m_r_f_s', 'discount_type', 'VARCHAR(16) NULL');
        $this->addColumn('m_r_f_s', 'discount_value', 'DECIMAL(15,2) NULL');
        $this->addColumn('m_r_f_s', 'discount_amount', 'DECIMAL(15,2) NULL');

        $this->forgetColumnCache();
    }

    public function down(): void
    {
        foreach (['discount_type', 'discount_value', 'discount_amount'] as $column) {
            if (Schema::hasTable('m_r_f_s') && Schema::hasColumn('m_r_f_s', $column)) {
                Schema::table('m_r_f_s', function (Blueprint $t) use ($column) {
                    $t->dropColumn($column);
                });
            }
        }

        $this->forgetColumnCache();
    }

    private function forgetColumnCache(): void
    {
        \App\Support\TableColumnCache::forget('m_r_f_s');
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
