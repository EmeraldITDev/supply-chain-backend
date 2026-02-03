<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plate_number' => 'sometimes|string|max:50',
            'type' => 'nullable|string|max:100',
            'capacity' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:50',
            'vendor_id' => 'nullable|exists:vendors,id',
            'gps_device_id' => 'nullable|string|max:100',
            'last_service_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];
    }
}
