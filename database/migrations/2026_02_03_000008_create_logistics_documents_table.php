<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('document_type', 100);
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_documents');
    }
};
