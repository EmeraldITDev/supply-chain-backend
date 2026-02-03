<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->unsignedBigInteger('journey_id')->nullable()->index();
            $table->string('report_type', 100);
            $table->string('status', 50)->index();
            $table->timestamp('submitted_at')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('logistics_trips')->nullOnDelete();
            $table->foreign('journey_id')->references('id')->on('logistics_journeys')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_reports');
    }
};
