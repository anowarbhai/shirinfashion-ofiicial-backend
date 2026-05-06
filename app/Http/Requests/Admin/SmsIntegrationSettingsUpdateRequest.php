<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SmsIntegrationSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', 'string', 'max:80'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'sender_id' => ['nullable', 'string', 'max:80'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'enable_customer_login_otp' => ['required', 'boolean'],
            'enable_admin_login_otp' => ['required', 'boolean'],
            'enable_order_otp' => ['required', 'boolean'],
            'enable_order_notification_sms' => ['required', 'boolean'],
            'customer_otp_template' => ['nullable', 'string', 'max:1000'],
            'admin_otp_template' => ['nullable', 'string', 'max:1000'],
            'order_otp_template' => ['nullable', 'string', 'max:1000'],
            'order_template' => ['nullable', 'string', 'max:1000'],
            'status_callback_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
