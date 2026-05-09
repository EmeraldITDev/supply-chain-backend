<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_completion_certificate_line_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('jcc_id')->index();
            $table->integer('line_number');
            $table->string('description');
            $table->string('item_type', 50); // vehicle, service, material, other
            $table->text('details')->nullable(); // JSON or free text
            $table->string('condition', 50)->nullable(); // good, fair, damaged, lost
            $table->text('remarks')->nullable();
            $table->string('reference_number')->nullable(); // Vendor submission reference or asset ID
            $table->unsignedBigInteger('vendor_submission_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('jcc_id')->references('id')->on('job_completion_certificates')->cascadeOnDelete();
            $table->foreign('vendor_submission_id')->references('id')->on('trip_vendor_submissions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_completion_certificate_line_items');
    }
};
