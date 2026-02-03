<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_vendor_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('vendor_name')->nullable();
            $table->string('token_hash', 128)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_vendor_invites');
    }
};
