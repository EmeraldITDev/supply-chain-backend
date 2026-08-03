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
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS driver_name VARCHAR(255) NULL');
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_phone')) {
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS driver_phone VARCHAR(255) NULL');
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_licence')) {
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS driver_licence VARCHAR(255) NULL');
        }

        if (! Schema::hasColumn('logistics_trips', 'driver_source')) {
            DB::statement('ALTER TABLE logistics_trips ADD COLUMN IF NOT EXISTS driver_source VARCHAR(255) NOT NULL DEFAULT \"system\"');
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
