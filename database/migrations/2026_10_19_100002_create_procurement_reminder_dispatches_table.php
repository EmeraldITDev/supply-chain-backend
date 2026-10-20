<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement reminder dispatch log.
 * Mirrors the logistics_fleet_notification_dispatches deduplication pattern.
 *
 * Unique key: (user_id, subject_type, subject_id, workflow_state, period_key)
 * period_key is a 24h window string: 'YYYY-MM-DD' — one reminder per day per
 * (user + record + state) combination, matching the existing 24h stale threshold.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('procurement_reminder_dispatches')) {
            return;
        }

        Schema::create('procurement_reminder_dispatches', function (Blueprint $table): void {
            $table->id();
            // The notified user
            $table->unsignedBigInteger('user_id');
            // Polymorphic subject: MRF, SRF, trip, etc.
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            // The workflow state that triggered the reminder
            $table->string('workflow_state', 80);
            // 24h window key (YYYY-MM-DD)
            $table->string('period_key', 16);
            // Optional assignment-gap flag (no assignee found)
            $table->boolean('assignment_gap')->default(false);
            // Delivery outcome tracking
            $table->string('status', 32)->default('sent'); // sent | failed
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(
                ['user_id', 'subject_type', 'subject_id', 'workflow_state', 'period_key'],
                'procurement_reminder_unique',
            );
            $table->index(['subject_type', 'subject_id'], 'procurement_reminder_subject_idx');
            $table->index('user_id', 'procurement_reminder_user_idx');
            $table->index('created_at', 'procurement_reminder_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_reminder_dispatches');
    }
};
