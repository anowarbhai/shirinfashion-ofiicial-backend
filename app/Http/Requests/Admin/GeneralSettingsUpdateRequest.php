<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GeneralSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
            'store_tagline' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'order_prefix' => ['required', 'string', 'max:20'],
            'default_currency' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:80'],
            'maintenance_mode' => ['required', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:1000'],
            'maintenance_until' => ['nullable', 'date'],
            'invoice_note' => ['nullable', 'string', 'max:1000'],
            'cookie_consent' => ['nullable', 'array'],
            'cookie_consent.enabled' => ['nullable', 'boolean'],
            'cookie_consent.message' => ['nullable', 'string', 'max:500'],
            'cookie_consent.accept_label' => ['nullable', 'string', 'max:80'],
            'cookie_consent.close_label' => ['nullable', 'string', 'max:80'],
            'cookie_consent.policy_label' => ['nullable', 'string', 'max:80'],
            'cookie_consent.policy_url' => ['nullable', 'string', 'max:255'],
        ];
    }
}
