<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FraudCheckerSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'provider' => $this->input('provider', 'onesoftcode'),
            'api_url' => $this->input('api_url', 'https://fraudchecker.ocs-api.top/api/v3'),
            'onesoftcode_api_key' => $this->input('onesoftcode_api_key', $this->input('api_key', '')),
            'bd_courier_api_key' => $this->input('bd_courier_api_key', ''),
            'bd_courier_api_url' => $this->input('bd_courier_api_url', 'https://api.bdcourier.com'),
            'couriers' => array_replace(
                [
                    'pathao' => true,
                    'steadfast' => true,
                    'parceldex' => true,
                    'redx' => true,
                    'paperfly' => true,
                    'carrybee' => true,
                ],
                is_array($this->input('couriers')) ? $this->input('couriers') : [],
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in(['onesoftcode', 'bd_courier'])],
            'api_key' => ['nullable', 'string', 'max:500'],
            'onesoftcode_api_key' => ['nullable', 'string', 'max:500'],
            'bd_courier_api_key' => ['nullable', 'string', 'max:500'],
            'api_url' => ['nullable', 'url', 'max:500'],
            'bd_courier_api_url' => ['nullable', 'url', 'max:500'],
            'couriers' => ['array'],
            'couriers.pathao' => ['boolean'],
            'couriers.steadfast' => ['boolean'],
            'couriers.parceldex' => ['boolean'],
            'couriers.redx' => ['boolean'],
            'couriers.paperfly' => ['boolean'],
            'couriers.carrybee' => ['boolean'],
            'auto_hold_high_risk' => ['required', 'boolean'],
            'block_disposable_email' => ['required', 'boolean'],
            'block_international_phone_mismatch' => ['required', 'boolean'],
            'risk_score_threshold' => ['required', 'integer', 'min:0', 'max:100'],
            'max_orders_per_phone_per_day' => ['required', 'integer', 'min:1', 'max:999'],
            'max_orders_per_ip_per_day' => ['required', 'integer', 'min:1', 'max:999'],
            'blacklist_phones' => ['array'],
            'blacklist_phones.*' => ['string', 'max:50'],
            'blacklist_ips' => ['array'],
            'blacklist_ips.*' => ['string', 'max:50'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
