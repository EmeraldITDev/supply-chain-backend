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
            'name' => 'nullable|string|max:255',
            'plate_number' => 'sometimes|string|max:50',
            'type' => 'nullable|string|max:100',
            'make_model' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:2100',
            'color' => 'nullable|string|max:50',
            'fuel_type' => 'nullable|string|max:50',
            'capacity' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:50',
            'vendor_id' => 'nullable|exists:vendors,id',
            'gps_device_id' => 'nullable|string|max:100',
            'last_service_at' => 'nullable|date',
            'metadata' => 'nullable|array',
        ];
    }
}
