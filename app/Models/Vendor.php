<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\VendorRegistrationDocument;

class Vendor extends Model
{
    use Notifiable;

    /**
     * Channel resolvers — the Notifiable trait will dispatch mail to
     * whichever column we return here. Vendor mail is sent to the primary
     * `email` column; alerts can be CC'd via additional columns if needed.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email ?: null;
    }

    protected $fillable = [
        'vendor_id',
        'name',
        'category',
        'category_other',
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
        'profile_completed',
        'onboarding_source',
        'onboarding_email_sent_at',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
        'total_orders' => 'integer',
        'annual_revenue' => 'string',
        'profile_completed' => 'boolean',
        'onboarding_email_sent_at' => 'datetime',
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
     * Portal users linked to this vendor account.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'vendor_id');
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

    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function findByEmailCaseInsensitive(string $email): ?self
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === '') {
            return null;
        }

        return self::query()
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(contact_person_email, \'\'))) = ?', [$normalized]);
            })
            ->orderBy('id')
            ->first();
    }

    public static function normalizeName(?string $name): string
    {
        $normalized = mb_strtolower(trim((string) $name));

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }

    public static function findByNormalizedName(string $name): ?self
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        return self::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve the vendors row for a portal login (role vendor). Uses
     * users.vendor_id when set; otherwise matches approved/active vendor by
     * user email or contact_person_email and persists vendor_id when found.
     */
    public static function forPortalUser(?User $user): ?Vendor
    {
        if (!$user) {
            return null;
        }

        $user->loadMissing('vendor');
        if ($user->vendor) {
            return $user->vendor;
        }

        if ($user->vendor_id) {
            $byId = self::find($user->vendor_id);
            if ($byId) {
                return $byId;
            }
        }

        $actsAsVendor = $user->scmRole() !== null && strcasecmp((string) $user->scmRole(), 'vendor') === 0;
        if (!$actsAsVendor && method_exists($user, 'hasRole')) {
            try {
                $actsAsVendor = $user->hasRole('vendor');
            } catch (\Throwable $e) {
                $actsAsVendor = false;
            }
        }
        if (!$actsAsVendor) {
            return null;
        }

        $email = trim((string) $user->email);
        if ($email === '') {
            return null;
        }

        $normalized = mb_strtolower($email);

        $candidates = self::query()
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(contact_person_email, \'\'))) = ?', [$normalized]);
            })
            ->orderBy('id')
            ->get();

        $resolved = $candidates->first(function (Vendor $v) {
            return in_array(strtolower(trim((string) ($v->status ?? ''))), ['approved', 'active'], true);
        });

        if ($resolved && $user->vendor_id === null) {
            try {
                $user->forceFill(['vendor_id' => $resolved->id])->saveQuietly();
            } catch (\Throwable $e) {
                Log::warning('Vendor::forPortalUser could not persist users.vendor_id', [
                    'user_id' => $user->id,
                    'vendor_id' => $resolved->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $resolved;
    }
}
