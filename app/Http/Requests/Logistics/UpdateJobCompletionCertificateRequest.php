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
            'certification_statement' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'service_period_start' => 'nullable|date',
            'service_period_end' => 'nullable|date',
            'currency' => 'nullable|string|size:3',
            'line_items' => 'nullable|array',
            'line_items.*.description' => 'required_with:line_items|string|max:2000',
            'line_items.*.unit' => 'nullable|string|max:50',
            'line_items.*.quantity' => 'nullable|numeric|min:0',
            'line_items.*.unit_price' => 'nullable|numeric|min:0',
            'line_items.*.unitPrice' => 'nullable|numeric|min:0',
            'line_items.*.amount' => 'nullable|numeric|min:0',
            'line_items.*.remarks' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_confirmed.boolean' => 'Delivery confirmed must be a boolean',
        ];
    }
}
