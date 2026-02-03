<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    use HasFactory;

    protected $table = 'logistics_documents';

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'document_type',
        'file_path',
        'file_name',
        'mime_type',
        'size',
        'expires_at',
        'issued_at',
        'uploaded_by',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'issued_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
