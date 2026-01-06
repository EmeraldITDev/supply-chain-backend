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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_id')->unique(); // QUO-2025-001 format
            $table->unsignedBigInteger('rfq_id')->nullable(); // Will add foreign key constraint later
            $table->unsignedBigInteger('vendor_id')->nullable(); // Will add foreign key constraint later
            $table->string('vendor_name');
            $table->decimal('price', 15, 2);
            $table->date('delivery_date');
            $table->text('notes')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->text('rejection_reason')->nullable();
            $table->text('approval_remarks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            
            $table->index(['rfq_id', 'status']);
            $table->index('vendor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
