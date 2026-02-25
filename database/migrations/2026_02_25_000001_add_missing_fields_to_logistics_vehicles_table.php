<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->string('name')->nullable()->after('vehicle_code');
            $table->string('make_model')->nullable()->after('type');
            $table->integer('year')->nullable()->after('make_model');
            $table->string('color')->nullable()->after('year');
            $table->string('fuel_type')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_vehicles', function (Blueprint $table) {
            $table->dropColumn(['name', 'make_model', 'year', 'color', 'fuel_type']);
        });
    }
};
