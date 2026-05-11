<?php

namespace App\Http\Requests\Logistics;

use App\Enums\MaterialCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaterialMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_name' => 'sometimes|string|max:255',
            'quantity' => 'sometimes|integer|min:1',
            'category' => 'sometimes|string|max:100',
            'pickup_location' => 'sometimes|string|max:255',
            'destination' => 'sometimes|string|max:255',
            'vendor_id' => 'nullable|exists:vendors,id',
            'vendor_name' => 'nullable|string|max:255',
            'vendor_phone' => 'nullable|string|max:20',
            'vehicle_plate_number' => 'sometimes|string|max:20',
            'driver_name' => 'sometimes|string|max:255',
            'driver_phone' => 'sometimes|string|max:20',
            'expected_pickup_datetime' => 'sometimes|date_format:Y-m-d H:i:s',
            'expected_delivery_datetime' => 'sometimes|date_format:Y-m-d H:i:s',
            'condition_of_goods' => ['sometimes', Rule::in(MaterialCondition::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'condition_of_goods.in' => 'Invalid condition of goods.',
            'vendor_id.exists' => 'The selected vendor does not exist.',
        ];
    }
}
