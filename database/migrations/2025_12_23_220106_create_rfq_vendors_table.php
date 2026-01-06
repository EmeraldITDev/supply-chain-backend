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
            $table->unsignedBigInteger('rfq_id'); // Will add foreign key constraint later
            $table->unsignedBigInteger('vendor_id'); // Will add foreign key constraint later
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
