<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_documents', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('uploaded_by');
            $table->index(['documentable_type', 'documentable_id', 'document_type', 'is_active'], 'logistics_documents_entity_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_documents', function (Blueprint $table) {
            $table->dropIndex('logistics_documents_entity_type_active_idx');
            $table->dropColumn('is_active');
        });
    }
};
