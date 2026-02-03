<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_journeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->string('status', 50)->index();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('last_checkpoint_at')->nullable();
            $table->string('last_checkpoint_location')->nullable();
            $table->string('vendor_status', 50)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('logistics_trips')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_journeys');
    }
};
