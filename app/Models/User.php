<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

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
}
