<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class SelectVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only approvers can select vendors
        return true; // TODO: Add role check
    }

    public function rules(): array
    {
        return [
            'vendor_id' => 'required|integer|exists:vendors,id',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Vendor selection is required',
            'vendor_id.exists' => 'The selected vendor is invalid',
        ];
    }
}
