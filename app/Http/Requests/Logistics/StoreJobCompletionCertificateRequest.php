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
            'certification_text' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'service_period_start' => 'nullable|date',
            'service_period_end' => 'nullable|date',
            'line_items' => 'nullable|array',
            'line_items.*.description' => 'required_with:line_items|string|max:2000',
            'line_items.*.item_type' => 'nullable|string|in:vehicle,service,material,other',
            'line_items.*.condition' => 'nullable|string|in:good,fair,damaged,lost',
            'line_items.*.remarks' => 'nullable|string|max:2000',
            'line_items.*.reference_number' => 'nullable|string|max:255',
            'line_items.*.trip_reference' => 'nullable|string|max:255',
            'line_items.*.vendor_submission_id' => 'nullable|integer|exists:trip_vendor_submissions,id',
            'line_items.*.details' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'delivery_confirmed.boolean' => 'Delivery confirmed must be a boolean',
        ];
    }
}
