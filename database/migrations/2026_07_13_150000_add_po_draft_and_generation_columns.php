<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO draft persistence + generation failure surfacing + index-friendly draft list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! Schema::hasColumn('m_r_f_s', 'po_type')) {
                $table->string('po_type', 32)->nullable()->after('po_terms_mode');
            }
            if (! Schema::hasColumn('m_r_f_s', 'po_payment_terms')) {
                $table->text('po_payment_terms')->nullable()->after('po_type');
            }
            if (! Schema::hasColumn('m_r_f_s', 'po_generation_error')) {
                $table->text('po_generation_error')->nullable()->after('po_draft_saved_at');
            }
            if (! Schema::hasColumn('m_r_f_s', 'po_generation_failed_at')) {
                $table->timestamp('po_generation_failed_at')->nullable()->after('po_generation_error');
            }
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' && ! $this->indexExists('m_r_f_s', 'mrfs_po_draft_active_idx')) {
            DB::statement(
                "CREATE INDEX mrfs_po_draft_active_idx ON m_r_f_s (po_draft_saved_at DESC)
                 WHERE po_draft_saved_at IS NOT NULL
                   AND (unsigned_po_url IS NULL OR unsigned_po_url = '')"
            );
        }
        \App\Support\TableColumnCache::forget('m_r_f_s');
    }

    public function down(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql' && $this->indexExists('m_r_f_s', 'mrfs_po_draft_active_idx')) {
            DB::statement('DROP INDEX IF EXISTS mrfs_po_draft_active_idx');
        }

        Schema::table('m_r_f_s', function (Blueprint $table) {
            foreach (['po_generation_failed_at', 'po_generation_error', 'po_payment_terms', 'po_type'] as $col) {
                if (Schema::hasColumn('m_r_f_s', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        \App\Support\TableColumnCache::forget('m_r_f_s');
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return $connection->selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index],
            ) !== null;
        }

        return false;
    }
};
