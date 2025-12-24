<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRegistration extends Model
{
    protected $fillable = [
        'company_name',
        'category',
        'email',
        'phone',
        'address',
        'tax_id',
        'contact_person',
        'status',
        'rejection_reason',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'vendor_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    /**
     * Get the vendor created from this registration (if approved)
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Get the user who approved this registration
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
