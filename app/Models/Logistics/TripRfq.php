<?php

namespace App\Models\Logistics;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripRfq extends Model
{
    protected $table = 'logistics_trip_rfqs';

    protected $fillable = [
        'trip_id',
        'vendor_id',
        'service_type',
        'details',
        'status',
        'quoted_price',
        'currency',
        'vendor_notes',
        'document_url',
        'valid_until',
        'is_recommended',
        'logistics_recommendation_note',
        'scd_approved',
        'scd_approved_at',
        'sent_at',
        'responded_at',
        'created_by',
    ];

    protected $casts = [
        'details' => 'array',
        'quoted_price' => 'decimal:2',
        'valid_until' => 'date',
        'is_recommended' => 'boolean',
        'scd_approved' => 'boolean',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
        'scd_approved_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
