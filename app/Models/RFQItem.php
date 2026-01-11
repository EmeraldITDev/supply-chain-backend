<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RFQItem extends Model
{
    use HasFactory;

    protected $table = 'rfq_items';

    protected $fillable = [
        'rfq_id',
        'item_name',
        'description',
        'quantity',
        'unit',
        'specifications',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * Get the RFQ that owns this item
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RFQ::class, 'rfq_id');
    }

    /**
     * Get quotation items for this RFQ item
     */
    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'rfq_item_id');
    }
}
