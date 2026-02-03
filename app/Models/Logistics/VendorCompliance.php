<?php

namespace App\Models\Logistics;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorCompliance extends Model
{
    use HasFactory;

    protected $table = 'logistics_vendor_compliances';

    protected $fillable = [
        'vendor_id',
        'status',
        'metadata',
        'reviewed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
