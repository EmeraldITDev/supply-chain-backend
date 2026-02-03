<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorInvite extends Model
{
    use HasFactory;

    protected $table = 'logistics_vendor_invites';

    protected $fillable = [
        'email',
        'vendor_name',
        'token_hash',
        'expires_at',
        'accepted_at',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
