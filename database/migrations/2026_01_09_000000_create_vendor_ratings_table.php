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
        Schema::create('vendor_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('rating', 2, 1)->comment('Rating from 1.0 to 5.0');
            $table->text('comment')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index('vendor_id');
            $table->index('user_id');
            $table->index('created_at');
            
            // Prevent duplicate ratings from same user (optional)
            // $table->unique(['vendor_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_ratings');
    }
};
