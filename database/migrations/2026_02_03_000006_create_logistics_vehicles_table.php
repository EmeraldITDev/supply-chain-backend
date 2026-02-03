<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_code')->unique();
            $table->string('plate_number')->unique();
            $table->string('type', 100)->nullable();
            $table->decimal('capacity', 10, 2)->nullable();
            $table->string('status', 50)->default('active');
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('gps_device_id')->nullable();
            $table->timestamp('last_service_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_vehicles');
    }
};
