<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->string('maintenance_type', 100);
            $table->text('description')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->unsignedBigInteger('performed_by')->nullable()->index();
            $table->string('status', 50)->default('completed');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('logistics_vehicles')->cascadeOnDelete();
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_vehicle_maintenances');
    }
};
