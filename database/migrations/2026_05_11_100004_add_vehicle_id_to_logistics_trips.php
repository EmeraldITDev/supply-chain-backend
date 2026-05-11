<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->after('vendor_id')->index();
            $table->foreign('vehicle_id')->references('id')->on('logistics_vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_trips', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn('vehicle_id');
        });
    }
};
