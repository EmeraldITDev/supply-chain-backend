<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class BulkUploadTripsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1',
            'rows.*.title' => 'required|string|max:255',
            'rows.*.origin' => 'required|string|max:255',
            'rows.*.destination' => 'required|string|max:255',
            'rows.*.scheduled_departure_at' => 'nullable|date',
            'rows.*.scheduled_arrival_at' => 'nullable|date',
        ];
    }
}
