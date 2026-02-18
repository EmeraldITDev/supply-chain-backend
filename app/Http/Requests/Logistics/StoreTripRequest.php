<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            return;
        }

        // Generate title from location if not provided and purpose is also not used
        if ($this->filled('origin') && $this->filled('destination')) {
            $this->merge([
                'title' => sprintf('Trip from %s to %s', $this->input('origin'), $this->input('destination')),
            ]);

            return;
        }

        // Fallback to purpose for title if no origin/destination
        if ($this->filled('purpose')) {
            $this->merge(['title' => $this->input('purpose')]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'purpose' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'trip_type' => 'nullable|in:personnel,material,mixed',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'status' => 'nullable|in:draft,scheduled,vendor_assigned,in_progress,completed,closed,cancelled',
            'scheduled_departure_at' => 'nullable|date',
            'scheduled_arrival_at' => 'nullable|date|after_or_equal:scheduled_departure_at',
            'origin' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'vendor_id' => 'nullable|exists:vendors,id',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
