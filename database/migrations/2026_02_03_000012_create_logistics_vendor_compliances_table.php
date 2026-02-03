<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_vendor_compliances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->unique();
            $table->string('status', 50)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_vendor_compliances');
    }
};
