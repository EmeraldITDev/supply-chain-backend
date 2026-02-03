<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    use HasFactory;

    protected $table = 'logistics_materials';

    protected $fillable = [
        'material_code',
        'name',
        'description',
        'trip_id',
        'quantity',
        'unit',
        'condition',
        'status',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'metadata' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function conditionHistory(): HasMany
    {
        return $this->hasMany(MaterialConditionHistory::class, 'material_id');
    }
}
