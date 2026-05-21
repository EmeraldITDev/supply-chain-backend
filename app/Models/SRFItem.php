<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SRFItem extends Model
{
    protected $table = 'srf_line_items';

    protected $fillable = [
        'srf_id',
        'item_name',
        'description',
        'quantity',
        'unit',
        'budget_amount',
        'quoted_amount',
        'unit_price',
        'total_price',
        'specifications',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'budget_amount' => 'decimal:2',
        'quoted_amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function srf(): BelongsTo
    {
        return $this->belongsTo(SRF::class, 'srf_id');
    }
}
