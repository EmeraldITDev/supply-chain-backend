<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreJourneyCheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'timestamp' => 'nullable|date',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('location')) {
            $location = $this->input('location')
                ?? $this->input('checkpoint_location')
                ?? $this->input('checkpoint_name')
                ?? $this->input('checkpointLocation');

            if ($location !== null) {
                $this->merge(['location' => $location]);
            }
        }
    }
}
