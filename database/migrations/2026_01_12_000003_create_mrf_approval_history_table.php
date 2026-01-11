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
        Schema::create('mrf_approval_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mrf_id')->constrained('m_r_f_s')->onDelete('cascade');
            $table->enum('action', ['approved', 'rejected', 'returned', 'generated_po', 'signed_po', 'rejected_po', 'payment_processed', 'payment_approved']);
            $table->string('stage', 50); // 'executive_review', 'chairman_review', 'procurement', etc.
            $table->foreignId('performed_by')->constrained('users');
            $table->string('performer_name');
            $table->string('performer_role', 100);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('mrf_id');
            $table->index('performed_by');
            $table->index(['mrf_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mrf_approval_history');
    }
};
