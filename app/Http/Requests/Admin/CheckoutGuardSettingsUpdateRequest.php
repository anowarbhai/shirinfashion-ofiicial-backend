<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

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
            'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }
}
