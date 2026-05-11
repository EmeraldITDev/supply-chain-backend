<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobCompletionCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'remarks' => 'nullable|string',
            'delivery_confirmed' => 'nullable|boolean',
            'condition_of_goods' => 'nullable|string',
            'certification_text' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'service_period_start' => 'nullable|date',
            'service_period_end' => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_confirmed.boolean' => 'Delivery confirmed must be a boolean',
        ];
    }
}
