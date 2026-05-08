<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class POTermsTemplate extends Model
{
    protected $table = 'po_terms_templates';

    protected $fillable = [
        'po_type',
        'content',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
