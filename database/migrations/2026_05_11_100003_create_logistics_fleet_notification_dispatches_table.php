<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_fleet_notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 255);
            $table->unsignedBigInteger('subject_id');
            $table->string('channel', 80);
            $table->string('period_key', 32);
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'channel', 'period_key'], 'fleet_notify_dispatch_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_fleet_notification_dispatches');
    }
};
