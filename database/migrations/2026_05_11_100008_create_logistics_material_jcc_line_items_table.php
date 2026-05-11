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
        Schema::create('logistics_material_jcc_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Reference to JCC
            $table->uuid('jcc_id')->index();
            $table->foreign('jcc_id')
                ->references('id')
                ->on('logistics_material_jccs')
                ->cascadeOnDelete();

            // Line item details
            $table->unsignedSmallInteger('serial_number');
            $table->string('material_name');
            $table->integer('quantity');
            $table->string('condition')->nullable();
            $table->text('remarks')->nullable();

            // Timestamps
            $table->timestamps();

            // Unique constraint per JCC
            $table->unique(['jcc_id', 'serial_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_material_jcc_line_items');
    }
};
