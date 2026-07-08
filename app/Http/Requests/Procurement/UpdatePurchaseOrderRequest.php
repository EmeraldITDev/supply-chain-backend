<?php

namespace App\Http\Requests\Procurement;

use App\Support\PurchaseOrderCurrency;
use App\Support\RequestLineItemParser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePurchaseOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        RequestLineItemParser::mergeIntoRequest($this);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->scmRole(), ['procurement_manager', 'procurement', 'supply_chain_director', 'admin'], true);
    }

    public function rules(): array
    {
        return array_merge([
            'title' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'quantity' => 'nullable|string',
            'department' => 'nullable|string|max:255',
            'estimatedCost' => 'nullable|numeric|min:0',
            'currency' => PurchaseOrderCurrency::VALIDATION_RULE,
            'poNumber' => 'nullable|string|max:255',
            'shipToAddress' => 'nullable|string|max:1000',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'taxAmount' => 'nullable|numeric|min:0',
            'customTerms' => 'nullable|string',
            'poTermsMode' => 'nullable|in:standard,custom,both',
            'poSpecialTerms' => 'nullable|string',
            'invoiceSubmissionEmail' => 'nullable|string|max:255',
            'invoiceSubmissionCc' => 'nullable|string|max:500',
            'selectedVendorId' => 'nullable|string|max:50',
        ], RequestLineItemParser::validationRules());
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Insufficient permissions to update purchase orders.',
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
