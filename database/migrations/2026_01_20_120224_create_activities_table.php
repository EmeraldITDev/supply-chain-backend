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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index(); // mrf_created, mrf_approved, rfq_sent, etc.
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name', 255)->nullable();
            $table->string('entity_type', 50)->nullable()->index(); // 'mrf', 'rfq', 'quotation', 'po', 'grn'
            $table->string('entity_id', 255)->nullable(); // UUID or ID string
            $table->string('status', 50)->nullable(); // pending, approved, rejected, etc.
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamp('created_at')->useCurrent()->index();
            
            // Foreign key constraint (if users table uses id)
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
