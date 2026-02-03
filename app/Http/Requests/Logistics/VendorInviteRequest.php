<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class VendorInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'vendor_name' => 'nullable|string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:30',
            'metadata' => 'nullable|array',
        ];
    }
}
