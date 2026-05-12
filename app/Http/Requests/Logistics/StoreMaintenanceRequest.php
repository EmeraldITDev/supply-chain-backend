<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'maintenance_type' => 'required|string|max:100',
            'interval_months' => 'nullable|integer|min:1|max:120',
            'description' => 'nullable|string',
            'performed_at' => 'nullable|date',
            'last_maintenance_date' => 'nullable|date',
            'next_due_at' => 'nullable|date',
            'next_maintenance_date' => 'nullable|date',
            'cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:SCHEDULED,COMPLETED,OVERDUE,scheduled,completed,overdue',
            'metadata' => 'nullable|array',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeFleetMaintenanceAliases();

        if ($this->has('last_maintenance_date') && !$this->has('performed_at')) {
            $this->merge(['performed_at' => $this->input('last_maintenance_date')]);
        }
        if ($this->has('next_maintenance_date') && !$this->has('next_due_at')) {
            $this->merge(['next_due_at' => $this->input('next_maintenance_date')]);
        }
    }

    /**
     * Fleet dashboard / SPA clients often send camelCase or UI labels (`type`, `date`)
     * instead of API field names (`maintenance_type`, `last_maintenance_date`).
     */
    protected function mergeFleetMaintenanceAliases(): void
    {
        if (!$this->filled('maintenance_type')) {
            $candidate = $this->input('type')
                ?? $this->input('maintenanceType')
                ?? $this->input('category');
            if (is_string($candidate) && trim($candidate) !== '') {
                $this->merge(['maintenance_type' => trim($candidate)]);
            }
        }

        $performed = $this->input('date')
            ?? $this->input('maintenanceDate')
            ?? $this->input('performedDate');
        if ($performed !== null && $performed !== ''
            && !$this->filled('last_maintenance_date') && !$this->filled('performed_at')) {
            $this->merge(['last_maintenance_date' => $performed]);
        }

        $next = $this->input('nextScheduledMaintenance')
            ?? $this->input('nextMaintenanceDate')
            ?? $this->input('nextDueDate');
        if ($next !== null && $next !== ''
            && !$this->filled('next_maintenance_date') && !$this->filled('next_due_at')) {
            $this->merge(['next_maintenance_date' => $next]);
        }

        if (!$this->filled('description')) {
            $notes = $this->input('notes');
            if (is_string($notes) && trim($notes) !== '') {
                $this->merge(['description' => trim($notes)]);
            }
        }

        if ($this->has('odometer')) {
            $existing = $this->input('metadata');
            $meta = is_array($existing) ? $existing : [];
            $meta['odometer_km'] = $this->input('odometer');
            $this->merge(['metadata' => $meta]);
        }
    }
}
