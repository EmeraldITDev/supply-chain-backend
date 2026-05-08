<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceComparison extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'vendor_id',
        'item_description',
        'unit_price',
        'quantity',
        'total_price',
        'is_selected',
        'selection_reason',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'total_price' => 'decimal:2',
        'is_selected' => 'boolean',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'purchase_order_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public static function getHistoricalAveragePrice(int $vendorId, string $itemDescription): ?float
    {
        $value = static::query()
            ->where('vendor_id', $vendorId)
            ->where('item_description', $itemDescription)
            ->whereHas('purchaseOrder', function ($query): void {
                $query->whereIn('workflow_state', ['po_signed', 'closed']);
            })
            ->avg('unit_price');

        return $value !== null ? (float) $value : null;
    }
}
