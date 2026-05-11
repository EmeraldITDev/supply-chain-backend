<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleFleetDocumentRequest extends FormRequest
{
    public const DOCUMENT_TYPES = [
        'INSURANCE_CERTIFICATE',
        'VEHICLE_LICENCE',
        'TRANSPORT_PERMIT',
        'ROAD_WORTHINESS_CERTIFICATE',
        'LOCAL_GOVERNMENT_PAPERS',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => 'required|string|in:' . implode(',', self::DOCUMENT_TYPES),
            'expiry_date' => 'required|date',
            'file' => 'required|file|max:10240',
        ];
    }
}
