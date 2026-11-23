<?php

namespace App\Http\Requests\Procurement;

use App\Services\PurchaseOrderRevisionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePurchaseOrderTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PurchaseOrderRevisionService::userCanEditTitle($this->user());
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:255',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Only Procurement Managers, Supply Chain Directors, and Admins can edit the purchase order title.',
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
