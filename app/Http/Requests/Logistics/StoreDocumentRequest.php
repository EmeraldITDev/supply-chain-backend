<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => 'required|string|in:vendor,trip,journey,vehicle',
            'entity_id' => 'required|integer',
            'document_type' => 'required|string|max:100',
            'file' => 'required|file|max:10240',
            'expires_at' => 'nullable|date',
            'issued_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];
    }
}
