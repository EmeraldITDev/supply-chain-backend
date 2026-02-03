<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('route', 191);
            $table->json('response');
            $table->unsignedSmallInteger('status_code');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_idempotency_keys');
    }
};
