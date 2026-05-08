<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Vendor can submit for their own trips
        // TODO: Add vendor authorization logic
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_make' => 'required|string|max:100',
            'vehicle_model' => 'required|string|max:100',
            'plate_number' => 'required|string|max:50|unique:trip_vendor_submissions',
            'driver_name' => 'required|string|max:100',
            'driver_phone' => 'required|string|max:20',
            'driver_license_no' => 'required|string|max:50',
            'security_info' => 'nullable|string',
            'quoted_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_make.required' => 'Vehicle make is required',
            'vehicle_model.required' => 'Vehicle model is required',
            'plate_number.required' => 'Plate number is required',
            'plate_number.unique' => 'This plate number is already registered',
            'driver_name.required' => 'Driver name is required',
            'driver_phone.required' => 'Driver phone is required',
            'driver_license_no.required' => 'Driver license number is required',
        ];
    }
}
