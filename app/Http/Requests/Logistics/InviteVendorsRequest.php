<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class InviteVendorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only approvers can invite vendors
        return true; // TODO: Add role check
    }

    public function rules(): array
    {
        return [
            'vendor_ids' => 'required|array|min:1',
            'vendor_ids.*' => 'required|integer|exists:vendors,id',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_ids.required' => 'At least one vendor must be invited',
            'vendor_ids.array' => 'Vendor IDs must be an array',
            'vendor_ids.min' => 'At least one vendor must be invited',
            'vendor_ids.*.exists' => 'One or more vendor IDs are invalid',
        ];
    }
}
