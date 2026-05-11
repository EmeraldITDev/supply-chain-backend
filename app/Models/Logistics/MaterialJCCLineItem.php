<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialJCCLineItem extends Model
{
    use HasFactory;

    protected $table = 'logistics_material_jcc_line_items';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'jcc_id',
        'serial_number',
        'material_name',
        'quantity',
        'condition',
        'remarks',
    ];

    protected $casts = [
        'serial_number' => 'integer',
        'quantity' => 'integer',
    ];

    /**
     * Boot function for model events.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Get the JCC associated with this line item.
     */
    public function jcc(): BelongsTo
    {
        return $this->belongsTo(MaterialJCC::class, 'jcc_id');
    }
}
