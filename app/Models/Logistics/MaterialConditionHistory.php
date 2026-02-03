<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialConditionHistory extends Model
{
    use HasFactory;

    protected $table = 'logistics_material_condition_histories';

    protected $fillable = [
        'material_id',
        'condition',
        'notes',
        'recorded_at',
        'recorded_by',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
