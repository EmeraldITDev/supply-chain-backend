<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class ApproveMaterialJCCRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No additional fields required for approval
            // The user ID comes from the authenticated request
        ];
    }
}
