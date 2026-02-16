<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('vehicle_code') || empty($this->input('vehicle_code'))) {
            $this->merge([
                'vehicle_code' => 'VEH-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
            ]);
        }

        // Auto-generate plate number if not provided (temporary placeholder)
        if (!$this->has('plate_number') || empty($this->input('plate_number'))) {
            $this->merge([
                'plate_number' => 'TEMP-' . Str::upper(Str::random(8)),
            ]);
        }

        // Map common frontend field names to backend field names
        $metadata = $this->input('metadata', []);
        
        if ($this->has('model')) {
            $this->merge(['type' => $this->input('model')]);
            $metadata['model'] = $this->input('model');
        }
        
        if ($this->has('year')) {
            $metadata['year'] = $this->input('year');
        }
        
        if ($this->has('cargo_capacity')) {
            $this->merge(['capacity' => $this->input('cargo_capacity')]);
        }
        
        if ($this->has('fuel_type')) {
            $metadata['fuel_type'] = $this->input('fuel_type');
        }

        if (!empty($metadata)) {
            $this->merge(['metadata' => $metadata]);
        }
    }

    public function rules(): array
    {
        return [
            'vehicle_code' => 'required|string|max:100',
            'plate_number' => 'required|string|max:50',
            'type' => 'nullable|string|max:100',
            'capacity' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|max:50',
            'vendor_id' => 'nullable|exists:vendors,id',
            'gps_device_id' => 'nullable|string|max:100',
            'last_service_at' => 'nullable|date',
            'metadata' => 'nullable|array',
            // Frontend field aliases
            'model' => 'nullable|string|max:100',
            'year' => 'nullable|integer|min:1900|max:2100',
            'cargo_capacity' => 'nullable|numeric|min:0',
            'fuel_type' => 'nullable|string|max:50',
        ];
    }
}
