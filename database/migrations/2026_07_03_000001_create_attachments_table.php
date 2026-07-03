<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attachments')) {
            return;
        }

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('collection', 64)->default('supporting_documents');
            $table->string('disk', 64);
            $table->text('file_path');
            $table->string('file_name');
            $table->string('original_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id', 'collection'], 'attachments_attachable_collection_idx');
            $table->index(['collection', 'created_at'], 'attachments_collection_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
