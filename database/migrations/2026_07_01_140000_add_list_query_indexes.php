<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'mrfs_status_created_idx');
            $table->index(['workflow_state', 'updated_at'], 'mrfs_workflow_updated_idx');
            $table->index('requester_id', 'mrfs_requester_idx');
            $table->index('po_number', 'mrfs_po_number_idx');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->index(['status', 'name'], 'vendors_status_name_idx');
            $table->index('created_at', 'vendors_created_idx');
        });

        if (Schema::hasTable('trips')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'trips_status_created_idx');
                $table->index('vendor_id', 'trips_vendor_idx');
                $table->index('scheduled_departure_at', 'trips_departure_idx');
            });
        }

        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'vehicles_status_created_idx');
                $table->index('vendor_id', 'vehicles_vendor_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('m_r_f_s', function (Blueprint $table) {
            $table->dropIndex('mrfs_status_created_idx');
            $table->dropIndex('mrfs_workflow_updated_idx');
            $table->dropIndex('mrfs_requester_idx');
            $table->dropIndex('mrfs_po_number_idx');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex('vendors_status_name_idx');
            $table->dropIndex('vendors_created_idx');
        });

        if (Schema::hasTable('trips')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->dropIndex('trips_status_created_idx');
                $table->dropIndex('trips_vendor_idx');
                $table->dropIndex('trips_departure_idx');
            });
        }

        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropIndex('vehicles_status_created_idx');
                $table->dropIndex('vehicles_vendor_idx');
            });
        }
    }
};
