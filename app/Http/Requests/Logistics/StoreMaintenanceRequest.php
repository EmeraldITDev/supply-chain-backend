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

        if ($this->filled('last_maintenance_date') && !$this->filled('performed_at')) {
            $this->merge(['performed_at' => $this->input('last_maintenance_date')]);
        }
        if ($this->filled('next_maintenance_date') && !$this->filled('next_due_at')) {
            $this->merge(['next_due_at' => $this->input('next_maintenance_date')]);
        }
    }

    /**
     * Fleet dashboard / SPA clients often send camelCase or UI labels (`type`, `date`)
     * instead of API field names (`maintenance_type`, `last_maintenance_date`).
     *
     * The Fleet "Add Maintenance" modal exposes `type` as a status selector
     * (Scheduled / Completed / Overdue), not the work category. So we route
     * the `type` value to `status` when it matches a known status, otherwise
     * treat it as a free-text maintenance_type.
     */
    protected function mergeFleetMaintenanceAliases(): void
    {
        $statusValues = ['SCHEDULED', 'COMPLETED', 'OVERDUE'];

        $rawType = $this->input('type')
            ?? $this->input('maintenanceType')
            ?? $this->input('category');

        if (is_string($rawType) && trim($rawType) !== '') {
            $rawType = trim($rawType);
            $normalized = strtoupper($rawType);
            if (in_array($normalized, $statusValues, true)) {
                if (!$this->filled('status')) {
                    $this->merge(['status' => $normalized]);
                }
            } elseif (!$this->filled('maintenance_type')) {
                $this->merge(['maintenance_type' => $rawType]);
            }
        }

        // If still empty, fall back to a sensible default derived from description.
        if (!$this->filled('maintenance_type')) {
            $fallback = is_string($this->input('description')) ? trim($this->input('description')) : '';
            if ($fallback === '' && is_string($this->input('notes'))) {
                $fallback = trim((string) $this->input('notes'));
            }
            if ($fallback !== '') {
                $this->merge(['maintenance_type' => substr($fallback, 0, 100)]);
            } else {
                $this->merge(['maintenance_type' => 'General Maintenance']);
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

        if (!$this->filled('description') && is_string($this->input('notes'))) {
            $notes = trim((string) $this->input('notes'));
            if ($notes !== '') {
                $this->merge(['description' => $notes]);
            }
        }

        if ($this->filled('odometer')) {
            $existing = $this->input('metadata');
            $meta = is_array($existing) ? $existing : [];
            $meta['odometer_km'] = $this->input('odometer');
            $this->merge(['metadata' => $meta]);
        }
    }
}
