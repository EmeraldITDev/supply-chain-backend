<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Atomic per-(supplier-token, day) serial counter backing the
 * PO-DDMMYY-SupplierToken-NNNN numbering scheme.
 */
class PoNumberSequence extends Model
{
    protected $fillable = [
        'scope_key',
        'last_serial',
    ];

    protected $casts = [
        'last_serial' => 'integer',
    ];
}
