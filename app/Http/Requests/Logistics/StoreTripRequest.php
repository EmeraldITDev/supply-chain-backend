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
        if (!$this->has('title') && $this->filled('purpose')) {
            $this->merge(['title' => $this->input('purpose')]);
        }
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:draft,scheduled,vendor_assigned,in_progress,completed,closed',
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
