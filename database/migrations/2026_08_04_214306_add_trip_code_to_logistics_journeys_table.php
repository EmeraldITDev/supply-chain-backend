<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('logistics_journeys', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_journeys', 'trip_code')) {
                $table->string('trip_code')->nullable()->after('trip_request_id');
            }
            if (! Schema::hasColumn('logistics_journeys', 'title')) {
                $table->string('title')->nullable()->after('trip_code');
            }
            if (! Schema::hasColumn('logistics_journeys', 'origin')) {
                $table->string('origin')->nullable()->after('title');
            }
            if (! Schema::hasColumn('logistics_journeys', 'driver_id')) {
                $table->string('driver_id')->nullable()->after('origin');
            }
            if (! Schema::hasColumn('logistics_journeys', 'driver_source')) {
                $table->string('driver_source')->nullable()->after('driver_id');
            }
            if (! Schema::hasColumn('logistics_journeys', 'escort_type')) {
                $table->string('escort_type')->nullable()->after('escort_description');
            }
            if (! Schema::hasColumn('logistics_journeys', 'vendor_id')) {
                $table->integer('vendor_id')->nullable()->after('escort_type');
            }
            if (! Schema::hasColumn('logistics_journeys', 'escort_required')) {
                $table->boolean('escort_required')->default(false)->after('vendor_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logistics_journeys', function (Blueprint $table) {
            $columns = ['trip_code', 'title', 'origin', 'driver_id', 'driver_source', 'escort_type', 'vendor_id', 'escort_required'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('logistics_journeys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
