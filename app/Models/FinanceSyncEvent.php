<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceSyncEvent extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'mrf_id',
        'scm_transaction_id',
        'direction',
        'event_type',
        'idempotency_key',
        'correlation_id',
        'payload_hash',
        'request_payload',
        'response_payload',
        'http_status',
        'status',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function mrf(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }
}
