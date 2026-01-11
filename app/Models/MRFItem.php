<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MRFItem extends Model
{
    use HasFactory;

    protected $table = 'mrf_items';

    protected $fillable = [
        'mrf_id',
        'item_name',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'total_price',
        'specifications',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    /**
     * Get the MRF that owns this item
     */
    public function mrf(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }
}
