<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public const CATEGORY_QUICKBOOKS = 'quickbooks';

    protected $fillable = [
        'category',
        'key',
        'value',
    ];
}
