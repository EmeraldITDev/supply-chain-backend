<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FleetDriver extends Model
{
    use HasFactory;

    protected $table = 'logistics_drivers';

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'license_number',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
