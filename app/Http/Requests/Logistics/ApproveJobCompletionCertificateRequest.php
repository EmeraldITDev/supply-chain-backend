<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class ApproveJobCompletionCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only approvers can approve JCC
        return true; // TODO: Add role check
    }

    public function rules(): array
    {
        return [
            'approval_remarks' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'approval_remarks.max' => 'Approval remarks cannot exceed 1000 characters',
        ];
    }
}
