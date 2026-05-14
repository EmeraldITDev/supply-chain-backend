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
        'category_other',
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
        'annual_revenue' => 'string',
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
     * Uploaded files for this registration (vendor_registration_documents table).
     * Named distinct from the JSON `documents` column to avoid Eloquent shadowing the attribute.
     */
    public function registrationDocuments(): HasMany
    {
        return $this->hasMany(VendorRegistrationDocument::class, 'vendor_registration_id');
    }

    /**
     * Document metadata for APIs: prefer persisted rows, then JSON column on registrations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDocumentsMetadataList(): array
    {
        $fromTable = $this->registrationDocuments()->orderBy('id')->get();
        if ($fromTable->isNotEmpty()) {
            return $fromTable->map(function (VendorRegistrationDocument $doc) {
                return [
                    'id' => $doc->id,
                    'file_path' => $doc->file_path,
                    'file_name' => $doc->file_name,
                    'file_type' => $doc->file_type,
                    'file_size' => $doc->file_size,
                    'file_url' => $doc->file_url,
                    'file_share_url' => $doc->file_share_url,
                    'uploaded_at' => $doc->uploaded_at?->toIso8601String(),
                ];
            })->all();
        }

        $raw = $this->attributes['documents'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }
}
