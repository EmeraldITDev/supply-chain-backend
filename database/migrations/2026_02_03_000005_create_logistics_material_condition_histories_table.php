<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_material_condition_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('material_id')->index();
            $table->string('condition', 50);
            $table->text('notes')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('material_id')->references('id')->on('logistics_materials')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_material_condition_histories');
    }
};
