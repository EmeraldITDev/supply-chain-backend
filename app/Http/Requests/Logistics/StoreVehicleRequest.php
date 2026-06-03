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
        if (!$this->filled('vehicle_code')) {
            $this->merge([
                'vehicle_code' => 'VEH-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
            ]);
        }

        if (! $this->filled('plate_number')) {
            foreach (['plate', 'registration_number', 'registrationNumber'] as $alias) {
                if ($this->filled($alias)) {
                    $this->merge(['plate_number' => trim((string) $this->input($alias))]);
                    break;
                }
            }
        }

        $plate = $this->input('plate_number');
        if ($plate === null || trim((string) $plate) === '') {
            $this->merge([
                'plate_number' => 'TEMP-' . Str::upper(Str::random(8)),
            ]);
        } else {
            $this->merge([
                'plate_number' => trim((string) $plate),
            ]);
        }

        if (!$this->filled('name') && $this->filled('vehicle_name')) {
            $this->merge(['name' => $this->input('vehicle_name')]);
        }

        $merge = [];

        $make = $this->filled('make') ? trim((string) $this->input('make')) : null;
        $model = $this->filled('model') ? trim((string) $this->input('model')) : null;

        if (!$this->filled('make_model')) {
            $combined = trim(($make ?? '') . ' ' . ($model ?? ''));
            if ($combined !== '') {
                $merge['make_model'] = $combined;
            }
        }
        if ($make !== null) {
            $merge['make'] = $make;
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

        $metadata = is_array($this->input('metadata')) ? $this->input('metadata') : [];
        $ownership = $this->input('ownership', $this->input('ownership_type'));
        if (is_string($ownership) && trim($ownership) !== '') {
            $metadata['ownership'] = $ownership;
        }
        if (!empty($metadata)) {
            $merge['metadata'] = $metadata;
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'vehicle_code' => 'required|string|max:100',
            'name' => 'nullable|string|max:255',
            'plate_number' => 'required|string|max:50',
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
