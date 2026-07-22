<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('logistics_vehicles', 'approval_status')) {
                $table->string('approval_status')->nullable()->after('status');
            }
            if (!Schema::hasColumn('logistics_vehicles', 'passenger_capacity')) {
                $table->integer('passenger_capacity')->nullable();
            }
            if (!Schema::hasColumn('logistics_vehicles', 'cargo_capacity')) {
                $table->string('cargo_capacity')->nullable();
            }
            if (!Schema::hasColumn('logistics_vehicles', 'fuel_type')) {
                $table->string('fuel_type')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'passenger_capacity', 'cargo_capacity', 'fuel_type']);
        });
    }
};
