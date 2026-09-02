<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MfsGatewaySettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'base_url' => ['required', 'string', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'accounts' => ['required', 'array'],
            'accounts.bkash' => ['required', 'array'],
            'accounts.bkash.enabled' => ['required', 'boolean'],
            'accounts.bkash.account_id' => ['nullable', 'string', 'max:255'],
            'accounts.bkash.number' => ['nullable', 'string', 'max:50'],
            'accounts.bkash.type' => ['nullable', 'string', 'in:personal,merchant,agent'],
            'accounts.bkash.instruction' => ['nullable', 'string', 'max:1000'],
            'accounts.nagad' => ['required', 'array'],
            'accounts.nagad.enabled' => ['required', 'boolean'],
            'accounts.nagad.account_id' => ['nullable', 'string', 'max:255'],
            'accounts.nagad.number' => ['nullable', 'string', 'max:50'],
            'accounts.nagad.type' => ['nullable', 'string', 'in:personal,merchant,agent'],
            'accounts.nagad.instruction' => ['nullable', 'string', 'max:1000'],
            'accounts.rocket' => ['required', 'array'],
            'accounts.rocket.enabled' => ['required', 'boolean'],
            'accounts.rocket.account_id' => ['nullable', 'string', 'max:255'],
            'accounts.rocket.number' => ['nullable', 'string', 'max:50'],
            'accounts.rocket.type' => ['nullable', 'string', 'in:personal,merchant,agent'],
            'accounts.rocket.instruction' => ['nullable', 'string', 'max:1000'],
            'accounts.upay' => ['required', 'array'],
            'accounts.upay.enabled' => ['required', 'boolean'],
            'accounts.upay.account_id' => ['nullable', 'string', 'max:255'],
            'accounts.upay.number' => ['nullable', 'string', 'max:50'],
            'accounts.upay.type' => ['nullable', 'string', 'in:personal,merchant,agent'],
            'accounts.upay.instruction' => ['nullable', 'string', 'max:1000'],
        ];
    }
}