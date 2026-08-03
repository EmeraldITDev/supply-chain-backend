<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_trips')) {
            return;
        }

        Schema::table('logistics_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('logistics_trips', 'driver_name')) {
                $table->string('driver_name')->nullable()->after('driver_user_id');
            }
            if (! Schema::hasColumn('logistics_trips', 'driver_phone')) {
                $table->string('driver_phone')->nullable()->after('driver_name');
            }
            if (! Schema::hasColumn('logistics_trips', 'driver_licence')) {
                $table->string('driver_licence')->nullable()->after('driver_phone');
            }
            if (! Schema::hasColumn('logistics_trips', 'driver_source')) {
                $table->string('driver_source')->default('system')->after('driver_licence');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistics_trips')) {
            return;
        }

        Schema::table('logistics_trips', function (Blueprint $table) {
            if (Schema::hasColumn('logistics_trips', 'driver_source')) {
                $table->dropColumn('driver_source');
            }
            if (Schema::hasColumn('logistics_trips', 'driver_licence')) {
                $table->dropColumn('driver_licence');
            }
            if (Schema::hasColumn('logistics_trips', 'driver_phone')) {
                $table->dropColumn('driver_phone');
            }
            if (Schema::hasColumn('logistics_trips', 'driver_name')) {
                $table->dropColumn('driver_name');
            }
        });
    }
};
