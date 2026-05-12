<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholder rows are created the moment a vendor is assigned to a trip so
 * the vendor portal can list the trip immediately. At that point the trip
 * details supplied by the vendor (vehicle make/model/plate, driver info,
 * licence number) are unknown. The original schema marked those columns NOT
 * NULL which caused vendor-assignment requests to fail with a SQL constraint
 * violation (surfaced to the frontend as a 503 "server unable to process").
 *
 * Relax those columns to nullable and drop the global unique on plate_number
 * (it shouldn't conflict with NULL but some DB engines treat it strictly).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('trip_vendor_submissions')) {
            return;
        }

        Schema::table('trip_vendor_submissions', function (Blueprint $table) {
            $table->string('vehicle_make')->nullable()->change();
            $table->string('vehicle_model')->nullable()->change();
            $table->string('plate_number')->nullable()->change();
            $table->string('driver_name')->nullable()->change();
            $table->string('driver_phone')->nullable()->change();
            $table->string('driver_license_no')->nullable()->change();
        });

        // Replace the strict global unique on plate_number with a non-unique
        // index. Plate numbers are only meaningful per submission, and the
        // global unique blocked re-using a plate across legitimate trips.
        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->dropUnique('trip_vendor_submissions_plate_number_unique');
            });
        } catch (\Throwable $e) {
            // Index may not exist on older databases; ignore.
        }

        try {
            Schema::table('trip_vendor_submissions', function (Blueprint $table) {
                $table->index('plate_number', 'trip_vendor_submissions_plate_number_index');
            });
        } catch (\Throwable $e) {
            // Index may already exist; ignore.
        }
    }

    public function down(): void
    {
        // We deliberately do not restore NOT NULL constraints; legacy data
        // would not satisfy them and the rollback path is not required by
        // any deployment.
    }
};
