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
            if (!Schema::hasColumn('logistics_vehicles', 'make')) {
                $table->string('make', 100)->nullable()->after('make_model');
            }
            if (!Schema::hasColumn('logistics_vehicles', 'passenger_capacity')) {
                $table->unsignedSmallInteger('passenger_capacity')->nullable()->after('capacity');
            }
        });

        // Backfill `make` from the leading token of `make_model` where possible
        // ("Toyota Hilux" -> make=Toyota), leaving `make_model` untouched.
        if (Schema::hasColumn('logistics_vehicles', 'make')) {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement("
                    UPDATE logistics_vehicles
                    SET make = split_part(make_model, ' ', 1)
                    WHERE make IS NULL
                      AND make_model IS NOT NULL
                      AND position(' ' in make_model) > 0
                ");
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement("
                    UPDATE logistics_vehicles
                    SET make = SUBSTRING_INDEX(make_model, ' ', 1)
                    WHERE make IS NULL
                      AND make_model IS NOT NULL
                      AND make_model LIKE '% %'
                ");
            }
        }
    }

    public function down(): void
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('logistics_vehicles', 'passenger_capacity')) {
                $table->dropColumn('passenger_capacity');
            }
            if (Schema::hasColumn('logistics_vehicles', 'make')) {
                $table->dropColumn('make');
            }
        });
    }
};
