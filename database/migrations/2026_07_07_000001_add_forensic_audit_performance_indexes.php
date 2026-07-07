<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('m_r_f_s')) {
            Schema::table('m_r_f_s', function (Blueprint $table) {
                if (! $this->indexExists('m_r_f_s', 'mrfs_po_draft_saved_at_idx')) {
                    $table->index('po_draft_saved_at', 'mrfs_po_draft_saved_at_idx');
                }

                if (
                    Schema::hasColumn('m_r_f_s', 'finance_ap_status')
                    && Schema::hasColumn('m_r_f_s', 'workflow_state')
                    && ! $this->indexExists('m_r_f_s', 'mrfs_finance_ap_status_workflow_idx')
                ) {
                    $table->index(
                        ['finance_ap_status', 'workflow_state'],
                        'mrfs_finance_ap_status_workflow_idx',
                    );
                }
            });

            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql' && ! $this->indexExists('m_r_f_s', 'mrfs_signed_po_url_present_idx')) {
                DB::statement(
                    'CREATE INDEX mrfs_signed_po_url_present_idx ON m_r_f_s (signed_po_url) WHERE signed_po_url IS NOT NULL AND signed_po_url <> \'\'',
                );
            } elseif ($driver === 'mysql' && ! $this->indexExists('m_r_f_s', 'mrfs_signed_po_url_idx')) {
                Schema::table('m_r_f_s', function (Blueprint $table) {
                    $table->index('signed_po_url', 'mrfs_signed_po_url_idx');
                });
            }
        }

        if (Schema::hasTable('activities')) {
            Schema::table('activities', function (Blueprint $table) {
                if (! $this->indexExists('activities', 'activities_entity_lookup_idx')) {
                    $table->index(
                        ['entity_type', 'entity_id', 'created_at'],
                        'activities_entity_lookup_idx',
                    );
                }
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if (! $this->indexExists('quotations', 'quotations_status_created_idx')) {
                    $table->index(['status', 'created_at'], 'quotations_status_created_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('m_r_f_s')) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql' && $this->indexExists('m_r_f_s', 'mrfs_signed_po_url_present_idx')) {
                DB::statement('DROP INDEX IF EXISTS mrfs_signed_po_url_present_idx');
            }

            Schema::table('m_r_f_s', function (Blueprint $table) {
                if ($this->indexExists('m_r_f_s', 'mrfs_signed_po_url_idx')) {
                    $table->dropIndex('mrfs_signed_po_url_idx');
                }
                if ($this->indexExists('m_r_f_s', 'mrfs_finance_ap_status_workflow_idx')) {
                    $table->dropIndex('mrfs_finance_ap_status_workflow_idx');
                }
                if ($this->indexExists('m_r_f_s', 'mrfs_po_draft_saved_at_idx')) {
                    $table->dropIndex('mrfs_po_draft_saved_at_idx');
                }
            });
        }

        if (Schema::hasTable('activities')) {
            Schema::table('activities', function (Blueprint $table) {
                if ($this->indexExists('activities', 'activities_entity_lookup_idx')) {
                    $table->dropIndex('activities_entity_lookup_idx');
                }
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if ($this->indexExists('quotations', 'quotations_status_created_idx')) {
                    $table->dropIndex('quotations_status_created_idx');
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $row = $connection->selectOne(
                'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index],
            );

            return $row !== null;
        }

        if ($driver === 'mysql') {
            $row = $connection->selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $index],
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = $connection->select('PRAGMA index_list('.$connection->getTablePrefix().$table.')');

            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
};
