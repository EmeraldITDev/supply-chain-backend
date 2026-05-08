<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_completion_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('trip_id')->unique()->index();
            $table->unsignedBigInteger('issued_by')->index();
            $table->timestamp('issued_at')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('delivery_confirmed')->default(false);
            $table->text('condition_of_goods')->nullable();
            $table->json('attachments')->nullable(); // array of file paths
            $table->string('status', 50)->default('draft'); // DRAFT | SUBMITTED | APPROVED
            $table->text('approval_remarks')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('logistics_trips')->cascadeOnDelete();
            $table->foreign('issued_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_completion_certificates');
    }
};
