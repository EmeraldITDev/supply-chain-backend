<?php

namespace App\Http\Requests\Procurement;

use App\Support\PurchaseOrderCurrency;
use App\Support\RequestLineItemParser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePurchaseOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        RequestLineItemParser::mergeIntoRequest($this);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->scmRole(), ['procurement_manager', 'procurement', 'admin'], true);
    }

    public function rules(): array
    {
        return array_merge([
            'title' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'contractType' => 'nullable|string|max:255',
            'urgency' => 'nullable|in:Low,Medium,High,Critical',
            'description' => 'nullable|string',
            'quantity' => 'nullable|string',
            'estimatedCost' => 'nullable|numeric|min:0',
            'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
            'justification' => 'nullable|string',
            'department' => 'nullable|string|max:255',
            'shipToAddress' => 'nullable|string|max:1000',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'taxAmount' => 'nullable|numeric|min:0',
        ], RequestLineItemParser::validationRules());
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Insufficient permissions to create purchase orders.',
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
