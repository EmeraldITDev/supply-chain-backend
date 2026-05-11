<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\VehicleMaintenance;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'maintenance_type' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'performed_at' => 'nullable|date',
            'last_maintenance_date' => 'nullable|date',
            'next_due_at' => 'nullable|date',
            'next_maintenance_date' => 'nullable|date',
            'interval_months' => 'nullable|integer|min:1|max:120',
            'cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:' . implode(',', [
                VehicleMaintenance::STATUS_SCHEDULED,
                VehicleMaintenance::STATUS_COMPLETED,
                VehicleMaintenance::STATUS_OVERDUE,
            ]),
            'metadata' => 'nullable|array',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('last_maintenance_date') && !$this->has('performed_at')) {
            $this->merge(['performed_at' => $this->input('last_maintenance_date')]);
        }
        if ($this->has('next_maintenance_date') && !$this->has('next_due_at')) {
            $this->merge(['next_due_at' => $this->input('next_maintenance_date')]);
        }
    }
}
