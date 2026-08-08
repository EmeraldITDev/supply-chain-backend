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
}
