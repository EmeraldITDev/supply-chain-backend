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
}
