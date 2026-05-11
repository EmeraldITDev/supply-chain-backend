<?php

use App\Enums\MaterialStatus;
use App\Enums\MaterialCondition;
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
        Schema::create('logistics_material_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Material details
            $table->string('material_name');
            $table->integer('quantity');
            $table->string('category');

            // Location details
            $table->string('pickup_location');
            $table->string('destination');

            // Vendor/Transporter details
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_phone')->nullable();

            // Vehicle details
            $table->string('vehicle_plate_number');

            // Driver details
            $table->string('driver_name');
            $table->string('driver_phone');

            // Timing details
            $table->dateTime('expected_pickup_datetime');
            $table->dateTime('expected_delivery_datetime');
            $table->dateTime('actual_pickup_datetime')->nullable();
            $table->dateTime('actual_delivery_datetime')->nullable();

            // Condition tracking
            $table->enum('condition_of_goods', MaterialCondition::values())->default(MaterialCondition::NEW->value);

            // Status tracking
            $table->enum('status', MaterialStatus::values())->default(MaterialStatus::PENDING->value)->index();

            // Audit fields
            $table->unsignedBigInteger('created_by')->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();

            // Timestamps
            $table->timestamps();

            // Foreign keys
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_material_movements');
    }
};
