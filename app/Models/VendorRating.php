<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRating extends Model
{
    protected $fillable = [
        'vendor_id',
        'user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'decimal:1',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the vendor this rating belongs to
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the user who created this rating
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
