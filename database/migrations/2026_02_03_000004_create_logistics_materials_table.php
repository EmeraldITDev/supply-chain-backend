<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_materials', function (Blueprint $table) {
            $table->id();
            $table->string('material_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('trip_id')->nullable()->index();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->string('unit', 50)->nullable();
            $table->string('condition', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('logistics_trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_materials');
    }
};
