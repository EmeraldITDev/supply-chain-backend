<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class BulkUploadMaterialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1',
            'rows.*.material_code' => 'required|string|max:100',
            'rows.*.name' => 'required|string|max:255',
            'rows.*.quantity' => 'required|numeric|min:0',
        ];
    }
}
