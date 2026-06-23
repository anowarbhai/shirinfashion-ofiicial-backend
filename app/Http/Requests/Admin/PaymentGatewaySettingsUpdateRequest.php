<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PaymentGatewaySettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'store_id' => ['nullable', 'string', 'max:255'],
            'store_password' => ['nullable', 'string', 'max:255'],
            'sandbox' => ['required', 'boolean'],
            'currency' => ['required', 'string', 'size:3'],
            'frontend_url' => ['nullable', 'url', 'max:255'],
            'callback_base_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
