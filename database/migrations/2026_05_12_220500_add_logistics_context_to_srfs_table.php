<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds first-class fleet/maintenance context to SRF records so that an SRF
 * initiated from the fleet maintenance dashboard carries the vehicle,
 * maintenance history and pre-filled RFQ payload all the way through the
 * Supply Chain Director → Procurement workflow.
 *
 * Without these columns the vendor RFQ has to be re-typed from scratch by
 * procurement which fails the "RFQ details pre-populated" QA acceptance
 * test (QA 2.2.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('s_r_f_s')) {
            return;
        }

        Schema::table('s_r_f_s', function (Blueprint $table) {
            if (!Schema::hasColumn('s_r_f_s', 'vehicle_id')) {
                $table->unsignedBigInteger('vehicle_id')->nullable()->after('justification')->index();
            }
            if (!Schema::hasColumn('s_r_f_s', 'maintenance_id')) {
                $table->unsignedBigInteger('maintenance_id')->nullable()->after('vehicle_id')->index();
            }
            if (!Schema::hasColumn('s_r_f_s', 'vehicle_snapshot')) {
                $table->json('vehicle_snapshot')->nullable()->after('maintenance_id');
            }
            if (!Schema::hasColumn('s_r_f_s', 'maintenance_history')) {
                $table->json('maintenance_history')->nullable()->after('vehicle_snapshot');
            }
            if (!Schema::hasColumn('s_r_f_s', 'rfq_prefill')) {
                $table->json('rfq_prefill')->nullable()->after('maintenance_history');
            }
            if (!Schema::hasColumn('s_r_f_s', 'origin')) {
                // 'fleet_dashboard' vs 'employee' vs 'logistics_manager' etc.
                $table->string('origin', 60)->nullable()->after('rfq_prefill');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('s_r_f_s')) {
            return;
        }

        Schema::table('s_r_f_s', function (Blueprint $table) {
            foreach (['vehicle_id', 'maintenance_id', 'vehicle_snapshot', 'maintenance_history', 'rfq_prefill', 'origin'] as $col) {
                if (Schema::hasColumn('s_r_f_s', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
