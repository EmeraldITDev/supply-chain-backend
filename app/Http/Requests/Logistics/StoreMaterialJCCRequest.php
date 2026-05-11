<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialJCCRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'certification_text' => 'nullable|string',
            'condition_on_arrival' => ['nullable', Rule::in(['good', 'damaged', 'partial'])],
            'po_number' => 'nullable|string|max:100',
            'line_items' => 'nullable|array',
            'line_items.*.material_name' => 'required_with:line_items|string|max:255',
            'line_items.*.quantity' => 'required_with:line_items|integer|min:1',
            'line_items.*.condition' => 'nullable|string|max:255',
            'line_items.*.remarks' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'condition_on_arrival.in' => 'Invalid condition on arrival value.',
        ];
    }
}
