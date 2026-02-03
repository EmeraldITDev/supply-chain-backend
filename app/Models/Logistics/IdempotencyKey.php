<?php

namespace App\Models\Logistics;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory;

    protected $table = 'logistics_idempotency_keys';

    protected $fillable = [
        'key',
        'user_id',
        'route',
        'response',
        'status_code',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}
