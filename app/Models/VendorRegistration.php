<?php

namespace App\Models;

use App\Enums\VendorRegistrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRegistration extends Model
{
    /**
     * Valid status values (must match database ENUM exactly)
     */
    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    protected $fillable = [
        'company_name',
        'category',
        'email',
        'phone',
        'address',
        'country_code',
        'bank_name',
        'account_number',
        'account_name',
        'currency',
        'annual_revenue',
        'number_of_employees',
        'year_established',
        'tax_id',
        'contact_person',
        'contact_person_email',
        'contact_person_phone',
        'contact_person_title',
        'city',
        'state',
        'postal_code',
        'alternate_phone',
        'website',
        'status',
        'rejection_reason',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'vendor_id',
        'documents',
        'temp_password',
        'password_changed_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'uploaded_at' => 'datetime',
        'documents' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    /**
     * Get documents for this registration
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VendorRegistrationDocument::class, 'vendor_registration_id');
    }
}
