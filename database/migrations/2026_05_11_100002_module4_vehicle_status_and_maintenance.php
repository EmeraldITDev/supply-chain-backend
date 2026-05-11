<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->string('status_inactive_reason', 50)->nullable()->after('status');
        });

        Schema::table('logistics_vehicle_maintenances', function (Blueprint $table) {
            $table->unsignedTinyInteger('interval_months')->nullable()->after('maintenance_type');
        });

        $this->normalizeVehicleStatuses();
        $this->normalizeMaintenanceStatuses();
    }

    public function down(): void
    {
        Schema::table('logistics_vehicle_maintenances', function (Blueprint $table) {
            $table->dropColumn('interval_months');
        });

        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->dropColumn('status_inactive_reason');
        });
    }

    private function normalizeVehicleStatuses(): void
    {
        $map = [
            'active' => 'ACTIVE',
            'inactive' => 'INACTIVE',
            'maintenance' => 'UNDER_MAINTENANCE',
            'MAINTENANCE' => 'UNDER_MAINTENANCE',
            'OUT_OF_SERVICE' => 'INACTIVE',
            'out_of_service' => 'INACTIVE',
        ];

        foreach ($map as $from => $to) {
            DB::table('logistics_vehicles')->where('status', $from)->update(['status' => $to]);
        }

        DB::table('logistics_vehicles')->whereNotIn('status', ['ACTIVE', 'INACTIVE', 'UNDER_MAINTENANCE'])
            ->update(['status' => 'ACTIVE']);
    }

    private function normalizeMaintenanceStatuses(): void
    {
        $map = [
            'completed' => 'COMPLETED',
            'scheduled' => 'SCHEDULED',
            'overdue' => 'OVERDUE',
        ];

        foreach ($map as $from => $to) {
            DB::table('logistics_vehicle_maintenances')->where('status', $from)->update(['status' => $to]);
        }
    }
};
