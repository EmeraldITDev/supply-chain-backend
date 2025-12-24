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
        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained('r_f_q_s')->onDelete('cascade');
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['rfq_id', 'vendor_id']);
            $table->index('rfq_id');
            $table->index('vendor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfq_vendors');
    }
};
