<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('name') && $this->filled('vehicle_name')) {
            $this->merge(['name' => $this->input('vehicle_name')]);
        }

        $merge = [];

        $hasMake = $this->filled('make');
        $hasModel = $this->filled('model');
        if (($hasMake || $hasModel) && !$this->filled('make_model')) {
            $combined = trim(
                ($hasMake ? trim((string) $this->input('make')) : '')
                . ' '
                . ($hasModel ? trim((string) $this->input('model')) : '')
            );
            if ($combined !== '') {
                $merge['make_model'] = $combined;
            }
        }
        if ($hasMake) {
            $merge['make'] = trim((string) $this->input('make'));
        }

        $cargoCapacity = $this->input('cargo_capacity', $this->input('cargoCapacity'));
        if ($cargoCapacity !== null && $cargoCapacity !== '' && !$this->filled('capacity')) {
            $merge['capacity'] = $cargoCapacity;
        }

        $passengerCapacity = $this->input('passenger_capacity', $this->input('passengerCapacity'));
        if ($passengerCapacity !== null && $passengerCapacity !== '') {
            $merge['passenger_capacity'] = $passengerCapacity;
        }

        if ($this->filled('vehicle_type') && !$this->filled('type')) {
            $merge['type'] = $this->input('vehicle_type');
        }

        $ownership = $this->input('ownership', $this->input('ownership_type'));
        if (is_string($ownership) && trim($ownership) !== '') {
            $metadata = is_array($this->input('metadata')) ? $this->input('metadata') : [];
            $metadata['ownership'] = $ownership;
            $merge['metadata'] = $metadata;
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'plate_number' => 'sometimes|string|max:50',
            'type' => 'nullable|string|max:100',
            'make' => 'nullable|string|max:100',
            'make_model' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:2100',
            'color' => 'nullable|string|max:50',
            'fuel_type' => 'nullable|string|max:50',
            'capacity' => 'nullable|numeric|min:0',
            'passenger_capacity' => 'nullable|integer|min:0|max:999',
            'status' => 'nullable|string|in:active,inactive,ACTIVE,INACTIVE,UNDER_MAINTENANCE,under_maintenance',
            'vendor_id' => 'nullable|exists:vendors,id',
            'gps_device_id' => 'nullable|string|max:100',
            'last_service_at' => 'nullable|date',
            'metadata' => 'nullable|array',
            // Accepted frontend aliases (consumed by prepareForValidation())
            'vehicle_name' => 'nullable|string|max:255',
            'vehicle_type' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'cargo_capacity' => 'nullable|numeric|min:0',
            'cargoCapacity' => 'nullable|numeric|min:0',
            'passengerCapacity' => 'nullable|integer|min:0|max:999',
            'ownership' => 'nullable|string|max:50',
            'ownership_type' => 'nullable|string|max:50',
        ];
    }
}
