<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip if table already exists (shared database with HRIS)
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 100)->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_type', 100)->nullable();
            $table->string('entity_type', 100)->nullable()->index();
            $table->string('entity_id', 100)->nullable()->index();
            $table->json('payload')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Do not drop if shared with HRIS
        // Schema::dropIfExists('audit_logs');
    }
};
