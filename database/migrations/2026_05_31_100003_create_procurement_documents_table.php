<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mrf_id')->constrained('m_r_f_s')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('type', 50);
            $table->string('file_name');
            $table->string('file_path');
            $table->text('file_url')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['mrf_id', 'type', 'is_active']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX procurement_documents_one_active_vendor_invoice
                ON procurement_documents (mrf_id, vendor_id)
                WHERE type = 'vendor_invoice' AND is_active = true AND vendor_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS procurement_documents_one_active_vendor_invoice');
        }

        Schema::dropIfExists('procurement_documents');
    }
};
