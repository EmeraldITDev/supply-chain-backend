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
        Schema::create('s_r_f_s', function (Blueprint $table) {
            $table->id();
            $table->string('srf_id')->unique(); // SRF-2025-001 format
            $table->string('title');
            $table->string('service_type');
            $table->enum('urgency', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->text('description');
            $table->string('duration');
            $table->decimal('estimated_cost', 15, 2);
            $table->text('justification');
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            $table->string('requester_name');
            $table->date('date');
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'In Progress', 'Completed'])->default('Pending');
            $table->string('current_stage')->default('procurement');
            $table->json('approval_history')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'date']);
            $table->index('requester_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_r_f_s');
    }
};
