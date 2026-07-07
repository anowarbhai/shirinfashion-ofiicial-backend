<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AiCallingSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'api_base_url' => ['required', 'url', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'store_name' => ['nullable', 'string', 'max:120'],
            'caller_id' => ['nullable', 'string', 'max:80'],
            'agent_extension' => ['nullable', 'string', 'max:40'],
            'cod_only' => ['required', 'boolean'],
            'confirmed_status' => ['required', 'string', 'max:80'],
            'rejected_status' => ['required', 'string', 'max:80'],
            'custom_text' => ['required', 'string', 'max:1000'],
            'confirm_text' => ['required', 'string', 'max:500'],
            'cancel_text' => ['required', 'string', 'max:500'],
            'request_timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'webhook_base_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
