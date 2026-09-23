<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutGuardSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'block_by_phone' => ['required', 'boolean'],
            'block_by_ip' => ['required', 'boolean'],
            'block_by_device' => ['required', 'boolean'],
            'protect_incomplete_orders' => ['required', 'boolean'],
            'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'message' => ['nullable', 'string', 'max:255'],
            'suspicious_otp_enabled' => ['required', 'boolean'],
            'suspicious_otp_by_phone' => ['required', 'boolean'],
            'suspicious_otp_by_ip' => ['required', 'boolean'],
            'suspicious_otp_by_device' => ['required', 'boolean'],
            'suspicious_otp_message' => ['nullable', 'string', 'max:255'],
            'surge_protection_enabled' => ['required', 'boolean'],
            'surge_window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'surge_order_threshold' => ['required', 'integer', 'min:2', 'max:10000'],
            'surge_action' => ['required', Rule::in(['otp_or_block', 'block'])],
            'surge_message' => ['nullable', 'string', 'max:255'],
        ];
    }
}
