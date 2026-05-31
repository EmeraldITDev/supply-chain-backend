<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_schedule_id')->constrained('payment_schedules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot_before')->nullable();
            $table->json('snapshot_after');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedule_versions');
    }
};
