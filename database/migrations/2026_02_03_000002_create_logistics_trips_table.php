<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 50)->index();
            $table->timestamp('scheduled_departure_at')->nullable();
            $table->timestamp('scheduled_arrival_at')->nullable();
            $table->timestamp('actual_departure_at')->nullable();
            $table->timestamp('actual_arrival_at')->nullable();
            $table->string('origin');
            $table->string('destination');
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_trips');
    }
};
