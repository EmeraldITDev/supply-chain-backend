<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if (! $this->indexExists('m_r_f_s', 'mrfs_vendor_workflow_po_signed_idx')) {
                $table->index(
                    ['selected_vendor_id', 'workflow_state', 'po_signed_at'],
                    'mrfs_vendor_workflow_po_signed_idx',
                );
            }
        });

        if (Schema::hasTable('logistics_trips')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                if (! $this->indexExists('logistics_trips', 'logistics_trips_status_created_idx')) {
                    $table->index(['status', 'created_at'], 'logistics_trips_status_created_idx');
                }
                if (! $this->indexExists('logistics_trips', 'logistics_trips_departure_idx')) {
                    $table->index('scheduled_departure_at', 'logistics_trips_departure_idx');
                }
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if (! $this->indexExists('quotations', 'quotations_vendor_rfq_idx')) {
                    $table->index(['vendor_id', 'rfq_id'], 'quotations_vendor_rfq_idx');
                }
            });
        }

        if (Schema::hasTable('vendor_registrations')) {
            Schema::table('vendor_registrations', function (Blueprint $table) {
                if (! $this->indexExists('vendor_registrations', 'vendor_registrations_status_created_idx')) {
                    $table->index(['status', 'created_at'], 'vendor_registrations_status_created_idx');
                }
            });
        }

        if (Schema::hasTable('rfq_vendors')) {
            Schema::table('rfq_vendors', function (Blueprint $table) {
                if (! $this->indexExists('rfq_vendors', 'rfq_vendors_vendor_rfq_idx')) {
                    $table->index(['vendor_id', 'rfq_id'], 'rfq_vendors_vendor_rfq_idx');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            if ($this->indexExists('m_r_f_s', 'mrfs_vendor_workflow_po_signed_idx')) {
                $table->dropIndex('mrfs_vendor_workflow_po_signed_idx');
            }
        });

        if (Schema::hasTable('logistics_trips')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                if ($this->indexExists('logistics_trips', 'logistics_trips_status_created_idx')) {
                    $table->dropIndex('logistics_trips_status_created_idx');
                }
                if ($this->indexExists('logistics_trips', 'logistics_trips_departure_idx')) {
                    $table->dropIndex('logistics_trips_departure_idx');
                }
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if ($this->indexExists('quotations', 'quotations_vendor_rfq_idx')) {
                    $table->dropIndex('quotations_vendor_rfq_idx');
                }
            });
        }

        if (Schema::hasTable('vendor_registrations')) {
            Schema::table('vendor_registrations', function (Blueprint $table) {
                if ($this->indexExists('vendor_registrations', 'vendor_registrations_status_created_idx')) {
                    $table->dropIndex('vendor_registrations_status_created_idx');
                }
            });
        }

        if (Schema::hasTable('rfq_vendors')) {
            Schema::table('rfq_vendors', function (Blueprint $table) {
                if ($this->indexExists('rfq_vendors', 'rfq_vendors_vendor_rfq_idx')) {
                    $table->dropIndex('rfq_vendors_vendor_rfq_idx');
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
