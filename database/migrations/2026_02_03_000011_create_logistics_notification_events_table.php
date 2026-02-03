<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 191)->unique();
            $table->string('type', 100)->index();
            $table->json('payload');
            $table->string('status', 50)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_notification_events');
    }
};
