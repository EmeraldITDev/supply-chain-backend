<?php

namespace App\Http\Requests\Logistics;

use App\Enums\MaterialStatus;
use App\Enums\MaterialCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'category' => 'required|string|max:100',
            'pickup_location' => 'required|string|max:255',
            'destination' => 'required|string|max:255',
            'vendor_id' => 'nullable|exists:vendors,id',
            'vendor_name' => 'nullable|string|max:255',
            'vendor_phone' => 'nullable|string|max:20',
            'vehicle_plate_number' => 'required|string|max:20',
            'driver_name' => 'required|string|max:255',
            'driver_phone' => 'required|string|max:20',
            'expected_pickup_datetime' => 'required|date_format:Y-m-d H:i:s|after:now',
            'expected_delivery_datetime' => 'required|date_format:Y-m-d H:i:s|after:expected_pickup_datetime',
            'condition_of_goods' => ['required', Rule::in(MaterialCondition::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_pickup_datetime.after' => 'Expected pickup time must be in the future.',
            'expected_delivery_datetime.after' => 'Expected delivery time must be after the pickup time.',
            'condition_of_goods.in' => 'Invalid condition of goods.',
            'vendor_id.exists' => 'The selected vendor does not exist.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $vendorId = $this->input('vendor_id');
            $vendorName = $this->input('vendor_name');

            // At least one of vendor_id or vendor_name must be present
            if (empty($vendorId) && empty($vendorName)) {
                $validator->errors()->add('vendor_id', 'Either vendor_id or vendor_name must be provided.');
            }
        });
    }
}
