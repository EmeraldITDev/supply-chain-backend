<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreJCCLineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:500',
            'item_type' => 'required|in:vehicle,service,material,other',
            'condition' => 'nullable|in:good,fair,damaged,lost',
            'remarks' => 'nullable|string|max:1000',
            'reference_number' => 'nullable|string|max:100',
            'vendor_submission_id' => 'nullable|integer|exists:trip_vendor_submissions,id',
            'details' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Item description is required',
            'item_type.required' => 'Item type is required',
            'item_type.in' => 'Item type must be vehicle, service, material, or other',
            'condition.in' => 'Condition must be good, fair, damaged, or lost',
        ];
    }
}
