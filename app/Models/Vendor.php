<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\VendorRegistrationDocument;

class Vendor extends Model
{
    protected $fillable = [
        'vendor_id',
        'name',
        'category',
        'rating',
        'total_orders',
        'status',
        'email',
        'phone',
        'alternate_phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country_code',
        'tax_id',
        'contact_person',
        'contact_person_title',
        'contact_person_email',
        'contact_person_phone',
        'website',
        'year_established',
        'number_of_employees',
        'annual_revenue',
        'notes',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
        'total_orders' => 'integer',
    ];

    /**
     * Get RFQs associated with this vendor
     */
    public function rfqs(): BelongsToMany
    {
        return $this->belongsToMany(RFQ::class, 'rfq_vendors', 'vendor_id', 'rfq_id')
            ->withTimestamps();
    }

    /**
     * Get quotations submitted by this vendor
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'vendor_id');
    }

    /**
     * Get vendor registrations
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(VendorRegistration::class, 'vendor_id');
    }

    /**
     * Get vendor documents
     */
    public function documents()
    {
        return $this->hasMany(VendorRegistrationDocument::class);
    }
    
    /**
     * Get vendor ratings
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(VendorRating::class, 'vendor_id');
    }

    /**
     * Generate Vendor ID
     */
    public static function generateVendorId(): string
    {
        $lastVendor = self::orderBy('vendor_id', 'desc')->first();

        if ($lastVendor && preg_match('/V(\d+)/', $lastVendor->vendor_id, $matches)) {
            $lastNumber = (int) $matches[1];
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "V{$newNumber}";
    }
}
