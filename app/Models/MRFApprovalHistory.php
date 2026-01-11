<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MRFApprovalHistory extends Model
{
    use HasFactory;

    protected $table = 'mrf_approval_history';

    protected $fillable = [
        'mrf_id',
        'action',
        'stage',
        'performed_by',
        'performer_name',
        'performer_role',
        'remarks',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the MRF this history belongs to
     */
    public function mrf(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }

    /**
     * Get the user who performed this action
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Create a history record
     */
    public static function record(MRF $mrf, string $action, string $stage, User $user, ?string $remarks = null): self
    {
        return self::create([
            'mrf_id' => $mrf->id,
            'action' => $action,
            'stage' => $stage,
            'performed_by' => $user->id,
            'performer_name' => $user->name,
            'performer_role' => $user->role,
            'remarks' => $remarks,
        ]);
    }
}
