<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:' . implode(',', [
                Vehicle::STATUS_ACTIVE,
                Vehicle::STATUS_INACTIVE,
                Vehicle::STATUS_UNDER_MAINTENANCE,
            ]),
            'reason' => 'required|string|max:2000',
            'override_by' => 'nullable|string|max:255',
        ];
    }
}
