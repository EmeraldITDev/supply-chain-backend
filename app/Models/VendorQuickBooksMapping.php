<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorQuickBooksMapping extends Model
{
    protected $table = 'vendor_quickbooks_mappings';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DEFERRED = 'deferred';

    protected $fillable = [
        'vendor_id',
        'realm_id',
        'currency',
        'quickbooks_vendor_id',
        'status',
        'match_method',
        'approved_by',
        'approved_at',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
