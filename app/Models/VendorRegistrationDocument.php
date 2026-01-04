<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRegistrationDocument extends Model
{
    protected $fillable = [
        'vendor_registration_id',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    /**
     * Get the vendor registration this document belongs to
     */
    public function vendorRegistration(): BelongsTo
    {
        return $this->belongsTo(VendorRegistration::class, 'vendor_registration_id');
    }
}
