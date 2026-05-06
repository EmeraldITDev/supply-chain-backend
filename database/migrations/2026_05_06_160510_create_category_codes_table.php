<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_codes', function (Blueprint $table) {
            $table->id();
            $table->string('category_name', 100)->unique();
            $table->string('code', 8);
            $table->string('request_type', 8); // MRF, SRF, etc.
            $table->timestamps();

            $table->index(['request_type', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_codes');
    }
};

