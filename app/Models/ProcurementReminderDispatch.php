<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks sent procurement approval reminders for deduplication.
 * Mirrors the logistics FleetNotificationDispatch pattern.
 */
class ProcurementReminderDispatch extends Model
{
    protected $table = 'procurement_reminder_dispatches';

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'workflow_state',
        'period_key',
        'assignment_gap',
        'status',
        'error_message',
    ];

    protected $casts = [
        'assignment_gap' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a reminder was already sent for this user + record + state today.
     */
    public static function alreadySent(
        int $userId,
        string $subjectType,
        int|string $subjectId,
        string $workflowState,
        string $periodKey,
    ): bool {
        return static::query()
            ->where('user_id', $userId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('workflow_state', $workflowState)
            ->where('period_key', $periodKey)
            ->exists();
    }

    /**
     * Record a successful dispatch.
     */
    public static function markSent(
        int $userId,
        string $subjectType,
        int|string $subjectId,
        string $workflowState,
        string $periodKey,
    ): void {
        static::firstOrCreate([
            'user_id'        => $userId,
            'subject_type'   => $subjectType,
            'subject_id'     => $subjectId,
            'workflow_state' => $workflowState,
            'period_key'     => $periodKey,
        ], [
            'assignment_gap' => false,
            'status'         => 'sent',
        ]);
    }

    /**
     * Record an assignment-gap flag (no qualified recipient found).
     */
    public static function markAssignmentGap(
        string $subjectType,
        int|string $subjectId,
        string $workflowState,
        string $periodKey,
    ): void {
        static::firstOrCreate([
            'user_id'        => 0, // sentinel for no-recipient
            'subject_type'   => $subjectType,
            'subject_id'     => $subjectId,
            'workflow_state' => $workflowState,
            'period_key'     => $periodKey,
        ], [
            'assignment_gap' => true,
            'status'         => 'failed',
            'error_message'  => 'No qualified assignee found for workflow state',
        ]);
    }
}
