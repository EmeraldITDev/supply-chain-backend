<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class MarkMaterialDeliveredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_delivery_datetime' => 'required|date_format:Y-m-d H:i:s',
        ];
    }

    public function messages(): array
    {
        return [
            'actual_delivery_datetime.required' => 'Actual delivery datetime is required.',
            'actual_delivery_datetime.date_format' => 'Actual delivery datetime must be in format Y-m-d H:i:s.',
        ];
    }
}
