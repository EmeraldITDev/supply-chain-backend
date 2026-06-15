<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementDocument extends Model
{
    public const TYPE_VENDOR_INVOICE = 'vendor_invoice';

    public const TYPE_GRN = 'grn';

    public const TYPE_WAYBILL = 'waybill';

    public const TYPE_JCC = 'jcc';

    public const TYPE_PFI = 'pfi';

    public const TYPE_PO_PDF = 'po_pdf';

    public const TYPE_SIGNED_PO = 'signed_po';

    public const TYPE_DELIVERY_CONFIRMATION = 'delivery_confirmation';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_VENDOR_INVOICE,
        self::TYPE_GRN,
        self::TYPE_WAYBILL,
        self::TYPE_JCC,
        self::TYPE_PFI,
        self::TYPE_PO_PDF,
        self::TYPE_SIGNED_PO,
        self::TYPE_DELIVERY_CONFIRMATION,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'mrf_id',
        'vendor_id',
        'type',
        'file_name',
        'file_path',
        'file_url',
        'uploaded_by',
        'uploaded_at',
        'version',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'is_active' => 'boolean',
        'version' => 'integer',
        'metadata' => 'array',
    ];

    public function mrf(): BelongsTo
    {
        return $this->belongsTo(MRF::class, 'mrf_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
