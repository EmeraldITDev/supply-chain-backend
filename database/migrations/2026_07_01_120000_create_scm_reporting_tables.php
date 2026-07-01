<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scm_generated_reports')) {
            Schema::create('scm_generated_reports', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('report_type', 64);
                $table->string('format', 16)->default('csv');
                $table->string('status', 32)->default('completed');
                $table->unsignedBigInteger('file_size_bytes')->nullable();
                $table->string('storage_path')->nullable();
                $table->json('filters')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['report_type', 'status']);
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('scm_scheduled_reports')) {
            Schema::create('scm_scheduled_reports', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('report_type', 64);
                $table->string('format', 16)->default('csv');
                $table->string('frequency', 32);
                $table->json('filters')->nullable();
                $table->json('recipient_user_ids')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('next_run_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['is_active', 'next_run_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scm_scheduled_reports');
        Schema::dropIfExists('scm_generated_reports');
    }
};
