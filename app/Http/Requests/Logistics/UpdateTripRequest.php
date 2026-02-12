<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('title') && $this->filled('purpose')) {
            $this->merge(['title' => $this->input('purpose')]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:draft,scheduled,vendor_assigned,in_progress,completed,closed',
            'scheduled_departure_at' => 'nullable|date',
            'scheduled_arrival_at' => 'nullable|date|after_or_equal:scheduled_departure_at',
            'actual_departure_at' => 'nullable|date',
            'actual_arrival_at' => 'nullable|date|after_or_equal:actual_departure_at',
            'origin' => 'sometimes|string|max:255',
            'destination' => 'sometimes|string|max:255',
            'vendor_id' => 'nullable|exists:vendors,id',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
