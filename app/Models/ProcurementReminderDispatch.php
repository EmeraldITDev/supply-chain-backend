<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Closure;

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
     * Whether a successful reminder was sent in the rolling 24-hour window.
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
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    /**
     * Serialize the check, delivery dispatch, and successful record so two
     * overlapping scheduler runs cannot send the same reminder.
     */
    public static function dispatchIfDue(
        int $userId,
        string $subjectType,
        int|string $subjectId,
        string $workflowState,
        string $periodKey,
        Closure $dispatch,
    ): bool {
        $lockKey = implode(':', [
            'procurement-reminder',
            $userId,
            md5($subjectType),
            $subjectId,
            md5($workflowState),
        ]);

        return Cache::lock($lockKey, 60)->block(5, function () use (
            $userId,
            $subjectType,
            $subjectId,
            $workflowState,
            $periodKey,
            $dispatch,
        ): bool {
            if (static::alreadySent($userId, $subjectType, $subjectId, $workflowState, $periodKey)) {
                return false;
            }

            $dispatch();
            static::markSent($userId, $subjectType, $subjectId, $workflowState, $periodKey);

            return true;
        });
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
