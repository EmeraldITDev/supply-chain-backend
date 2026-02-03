<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJourneyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:not_started,departed,en_route,arrived,closed',
            'timestamp' => 'nullable|date',
            'location' => 'nullable|string|max:255',
        ];
    }
}
