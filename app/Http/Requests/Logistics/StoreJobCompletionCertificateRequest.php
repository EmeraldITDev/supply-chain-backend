<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreJobCompletionCertificateRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_confirmed.boolean' => 'Delivery confirmed must be a boolean',
        ];
    }
}
