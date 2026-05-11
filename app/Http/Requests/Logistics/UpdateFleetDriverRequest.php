<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFleetDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'sometimes|string|max:20',
            'license_number' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ];
    }
}
