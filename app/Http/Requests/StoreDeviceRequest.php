<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->is_active;
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:120'],
            'imei' => ['required', 'digits_between:14,16', 'unique:devices,imei'],
            'secondary_imei' => ['nullable', 'digits_between:14,16', 'different:imei', 'unique:devices,secondary_imei'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'shop_branch' => ['nullable', 'string', 'max:120'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'management_mode' => ['required', 'in:standard,managed'],
            'management_pin' => ['required', 'digits:4', 'confirmed', 'not_in:0000,1111,1234,4321'],
            'location_tracking_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
