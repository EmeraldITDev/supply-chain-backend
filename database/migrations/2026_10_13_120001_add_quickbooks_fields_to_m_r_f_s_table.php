<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        $this->addColumn('quickbooks_po_id', 'VARCHAR(255) NULL');
        $this->addColumn('quickbooks_synced_at', 'TIMESTAMP NULL');
        $this->addColumn('quickbooks_sync_status', 'VARCHAR(50) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            foreach (['quickbooks_po_id', 'quickbooks_synced_at', 'quickbooks_sync_status'] as $column) {
                if (Schema::hasColumn('m_r_f_s', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addColumn(string $column, string $definition): void
    {
        if (Schema::hasColumn('m_r_f_s', $column)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE m_r_f_s ADD COLUMN IF NOT EXISTS {$column} {$definition}");

            return;
        }

        DB::statement("ALTER TABLE m_r_f_s ADD COLUMN {$column} {$definition}");
    }
};
