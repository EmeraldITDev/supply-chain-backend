<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! $this->indexExists('m_r_f_s', 'mrfs_po_signed_at_idx')) {
                $table->index('po_signed_at', 'mrfs_po_signed_at_idx');
            }
            if (! $this->indexExists('m_r_f_s', 'mrfs_created_po_signed_idx')) {
                $table->index(['created_at', 'po_signed_at'], 'mrfs_created_po_signed_idx');
            }
        });

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if (! $this->indexExists('quotations', 'quotations_updated_at_idx')) {
                    $table->index('updated_at', 'quotations_updated_at_idx');
                }
            });
        }

        if (Schema::hasTable('price_comparisons')) {
            Schema::table('price_comparisons', function (Blueprint $table) {
                if (! $this->indexExists('price_comparisons', 'price_comparisons_po_created_idx')) {
                    $table->index(['purchase_order_id', 'created_at'], 'price_comparisons_po_created_idx');
                }
            });
        }

        if (Schema::hasTable('mrf_items')) {
            Schema::table('mrf_items', function (Blueprint $table) {
                if (! $this->indexExists('mrf_items', 'mrf_items_mrf_quoted_idx')) {
                    $table->index(['mrf_id', 'quoted_amount'], 'mrf_items_mrf_quoted_idx');
                }
            });
        }

        if (Schema::hasTable('srf_items')) {
            Schema::table('srf_items', function (Blueprint $table) {
                if (! $this->indexExists('srf_items', 'srf_items_srf_quoted_idx')) {
                    $table->index(['srf_id', 'quoted_amount'], 'srf_items_srf_quoted_idx');
                }
            });
        }

        if (Schema::hasTable('materials')) {
            Schema::table('materials', function (Blueprint $table) {
                if (! $this->indexExists('materials', 'materials_status_updated_idx')) {
                    $table->index(['status', 'updated_at'], 'materials_status_updated_idx');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if ($this->indexExists('m_r_f_s', 'mrfs_po_signed_at_idx')) {
                $table->dropIndex('mrfs_po_signed_at_idx');
            }
            if ($this->indexExists('m_r_f_s', 'mrfs_created_po_signed_idx')) {
                $table->dropIndex('mrfs_created_po_signed_idx');
            }
        });

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if ($this->indexExists('quotations', 'quotations_updated_at_idx')) {
                    $table->dropIndex('quotations_updated_at_idx');
                }
            });
        }

        if (Schema::hasTable('price_comparisons')) {
            Schema::table('price_comparisons', function (Blueprint $table) {
                if ($this->indexExists('price_comparisons', 'price_comparisons_po_created_idx')) {
                    $table->dropIndex('price_comparisons_po_created_idx');
                }
            });
        }

        if (Schema::hasTable('mrf_items')) {
            Schema::table('mrf_items', function (Blueprint $table) {
                if ($this->indexExists('mrf_items', 'mrf_items_mrf_quoted_idx')) {
                    $table->dropIndex('mrf_items_mrf_quoted_idx');
                }
            });
        }

        if (Schema::hasTable('srf_items')) {
            Schema::table('srf_items', function (Blueprint $table) {
                if ($this->indexExists('srf_items', 'srf_items_srf_quoted_idx')) {
                    $table->dropIndex('srf_items_srf_quoted_idx');
                }
            });
        }

        if (Schema::hasTable('materials')) {
            Schema::table('materials', function (Blueprint $table) {
                if ($this->indexExists('materials', 'materials_status_updated_idx')) {
                    $table->dropIndex('materials_status_updated_idx');
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

        return false;
    }
};
