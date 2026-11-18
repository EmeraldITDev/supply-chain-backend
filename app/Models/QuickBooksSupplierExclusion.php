<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickBooksSupplierExclusion extends Model
{
    protected $fillable = [
        'realm_id',
        'quickbooks_vendor_id',
        'currency',
        'reason',
        'excluded_by',
        'excluded_at',
        'restored_by',
        'restored_at',
        'status',
    ];

    protected $casts = [
        'excluded_at' => 'datetime',
        'restored_at' => 'datetime',
    ];

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
