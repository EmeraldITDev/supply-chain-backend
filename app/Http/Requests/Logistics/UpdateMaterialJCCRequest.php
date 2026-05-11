<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialJCCRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'certification_text' => 'sometimes|string',
            'condition_on_arrival' => ['sometimes', Rule::in(['good', 'damaged', 'partial'])],
            'po_number' => 'sometimes|string|max:100',
            'line_items' => 'sometimes|array',
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
