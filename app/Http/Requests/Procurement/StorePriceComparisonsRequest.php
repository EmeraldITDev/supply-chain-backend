<?php

namespace App\Http\Requests\Procurement;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePriceComparisonsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row) || !array_key_exists('vendor_id', $row)) {
                continue;
            }
            $rows[$i]['vendor_id'] = trim((string) $row['vendor_id']);
        }

        $this->merge(['rows' => $rows]);
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['procurement_manager', 'procurement', 'admin'], true);
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:2',
            'rows.*.vendor_id' => 'required|string|exists:vendors,vendor_id',
            'rows.*.item_description' => 'required|string|max:1000',
            'rows.*.unit_price' => 'required|numeric|min:0',
            'rows.*.quantity' => 'required|numeric|gt:0',
            'rows.*.is_selected' => 'nullable|boolean',
            'rows.*.selection_reason' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'At least two supplier rows are required.',
            'rows.min' => 'At least two supplier rows are required.',
            'rows.*.vendor_id.exists' => 'One of the supplied vendors does not exist.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $rows = $this->input('rows', []);
            if (!is_array($rows)) {
                return;
            }

            $selectedCount = 0;
            foreach ($rows as $row) {
                if (is_array($row) && filter_var($row['is_selected'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $selectedCount++;
                }
            }

            if ($selectedCount === 0) {
                $v->errors()->add('rows', 'Exactly one supplier row must be marked as selected.');
            } elseif ($selectedCount > 1) {
                $v->errors()->add('rows', 'Only one supplier row can be marked as selected.');
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => 'Insufficient permissions to manage price comparisons.',
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
