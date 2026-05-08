<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_vendor_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('vehicle_make');
            $table->string('vehicle_model');
            $table->string('plate_number')->unique();
            $table->string('driver_name');
            $table->string('driver_phone');
            $table->string('driver_license_no');
            $table->text('security_info')->nullable();
            $table->decimal('quoted_price', 15, 2)->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 50)->default('pending'); // PENDING | SUBMITTED | APPROVED | REJECTED
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('logistics_trips')->cascadeOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->cascadeOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['trip_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_vendor_submissions');
    }
};
