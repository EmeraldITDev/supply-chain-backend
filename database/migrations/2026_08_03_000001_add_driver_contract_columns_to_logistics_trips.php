<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_trips')) {
            return;
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_name')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $table->string('driver_name')->nullable();
            });
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_phone')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $table->string('driver_phone')->nullable();
            });
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_licence')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $table->string('driver_licence')->nullable();
            });
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_source')) {
            Schema::table('logistics_trips', function (Blueprint $table) {
                $table->string('driver_source')->nullable()->default('system');
            });
        }
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
