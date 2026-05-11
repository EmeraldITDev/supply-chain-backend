<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class MarkMaterialInTransitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actual_pickup_datetime' => 'required|date_format:Y-m-d H:i:s',
        ];
    }

    public function messages(): array
    {
        return [
            'actual_pickup_datetime.required' => 'Actual pickup datetime is required.',
            'actual_pickup_datetime.date_format' => 'Actual pickup datetime must be in format Y-m-d H:i:s.',
        ];
    }
}
