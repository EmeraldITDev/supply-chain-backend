<?php

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
        Schema::create('vendor_registration_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_registration_id')->constrained('vendor_registrations')->onDelete('cascade');
            $table->string('file_path'); // Path to stored file
            $table->string('file_name'); // Original filename
            $table->string('file_type')->nullable(); // MIME type
            $table->unsignedBigInteger('file_size')->nullable(); // File size in bytes
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();

            $table->index('vendor_registration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_registration_documents');
    }
};
