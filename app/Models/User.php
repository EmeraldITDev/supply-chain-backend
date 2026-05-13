<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'department',
        'designated_requisition_creator',
        'signature_image_path',
        'phone',
        'employee_id',
        'vendor_id',
        'must_change_password',
        'password_changed_at',
        'is_admin',
        'can_manage_users',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'designated_requisition_creator' => 'boolean',
        ];
    }

    /**
     * Get MRFs requested by this user
     */
    public function mrfs()
    {
        return $this->hasMany(MRF::class, 'requester_id');
    }

    /**
     * Get SRFs requested by this user
     */
    public function srfs()
    {
        return $this->hasMany(SRF::class, 'requester_id');
    }

    /**
     * Get RFQs created by this user
     */
    public function createdRfqs()
    {
        return $this->hasMany(RFQ::class, 'created_by');
    }

    /**
     * Get quotations approved by this user
     */
    public function approvedQuotations()
    {
        return $this->hasMany(Quotation::class, 'approved_by');
    }

    /**
     * Get vendor registrations approved by this user
     */
    public function approvedVendorRegistrations()
    {
        return $this->hasMany(VendorRegistration::class, 'approved_by');
    }

    /**
     * Get the employee associated with this user.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the vendor associated with this user (if user is a vendor)
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * Resolve the vendor portal User row for an email address used on login /
     * password reset: matches vendor users by users.email (case-insensitive) or,
     * like VendorAuthController::login, via an approved/active vendors row
     * (primary or contact_person_email) linked by vendor_id.
     */
    public static function findVendorPortalUserByEmail(string $email): ?self
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        $byUserEmail = self::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
            ->where(function ($query) {
                $query->where('role', 'vendor')
                    ->orWhereHas('roles', function ($q) {
                        $q->where('name', 'vendor');
                    });
            })
            ->first();

        if ($byUserEmail) {
            return $byUserEmail;
        }

        $vendor = Vendor::query()
            ->where(function ($q) use ($normalized) {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(contact_person_email, \'\'))) = ?', [$normalized]);
            })
            ->orderBy('id')
            ->get()
            ->first(function (Vendor $v) {
                return in_array(strtolower(trim((string) ($v->status ?? ''))), ['approved', 'active'], true);
            });

        if (!$vendor) {
            return null;
        }

        return self::query()
            ->where('vendor_id', $vendor->id)
            ->where(function ($query) {
                $query->where('role', 'vendor')
                    ->orWhereHas('roles', function ($q) {
                        $q->where('name', 'vendor');
                    });
            })
            ->first();
    }
}
