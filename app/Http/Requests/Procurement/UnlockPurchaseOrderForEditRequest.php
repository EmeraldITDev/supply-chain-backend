<?php

namespace App\Http\Requests\Procurement;

use App\Services\PurchaseOrderRevisionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UnlockPurchaseOrderForEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PurchaseOrderRevisionService::userCanRevise($this->user());
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:3|max:2000',
            'unlock_reason' => 'nullable|string|min:3|max:2000',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Only Procurement Managers and Admins can unlock a signed purchase order.',
            'code' => 'FORBIDDEN',
        ], 403));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Validation failed',
            'errors' => $validator->errors(),
            'code' => 'VALIDATION_ERROR',
        ], 422));
    }
}
