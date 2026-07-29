<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        return auth()->check()
            && $user->is_active
            && ($user->isSuperAdmin() || (
                (! $user->shop_id && $user->role === 'admin')
                || (
                    $user->shop?->status === 'active'
                    && $user->shop->device_registration_enabled
                    && $user->canShop('devices')
                )
            ));
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'customer_name' => ['required_without:customer_id', 'string', 'max:120'],
            'customer_phone' => ['required_without:customer_id', 'string', 'max:30'],
            'customer_address' => [Rule::requiredIf(fn()=>auth()->user()?->shop_id && !$this->customer_id), 'nullable', 'string', 'max:1000'],
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
            'storage'=>['nullable','string','max:50'],'colour'=>['nullable','string','max:50'],'invoice_number'=>['nullable','string','max:100'],
            'first_payment'=>[Rule::requiredIf(fn()=>auth()->user()?->shop_id),'nullable','numeric','min:0','lte:selling_price'],
            'number_of_installments'=>[Rule::requiredIf(fn()=>auth()->user()?->shop_id),'nullable','integer','min:1','max:120'],
            'first_due_date'=>[Rule::requiredIf(fn()=>auth()->user()?->shop_id),'nullable','date'],
            'payment_frequency'=>[Rule::requiredIf(fn()=>auth()->user()?->shop_id),'nullable','in:monthly,weekly,custom'],
            'custom_frequency_days'=>['required_if:payment_frequency,custom','nullable','integer','min:1','max:365'],
            'installment_amount'=>[Rule::requiredIf(fn()=>auth()->user()?->shop_id),'nullable','numeric','min:0.01'],
            'custom_commission_amount'=>['nullable','numeric','min:0'],
        ];
    }
}
