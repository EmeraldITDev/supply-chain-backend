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
        Schema::create('r_f_q_s', function (Blueprint $table) {
            $table->id();
            $table->string('rfq_id')->unique(); // RFQ-2025-001 format
            $table->foreignId('mrf_id')->nullable()->constrained('m_r_f_s')->onDelete('cascade');
            $table->string('mrf_title')->nullable();
            $table->text('description');
            $table->string('quantity');
            $table->decimal('estimated_cost', 15, 2);
            $table->date('deadline');
            $table->enum('status', ['Open', 'Closed', 'Awarded', 'Cancelled'])->default('Open');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['status', 'deadline']);
            $table->index('mrf_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('r_f_q_s');
    }
};
