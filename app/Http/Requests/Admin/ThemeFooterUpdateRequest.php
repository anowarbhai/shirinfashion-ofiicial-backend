<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeFooterUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'active_style' => ['required', Rule::in(['style-1', 'style-2', 'style-3'])],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'about_text' => ['nullable', 'string', 'max:5000'],
            'copyright_text' => ['required', 'string', 'max:255'],
            'newsletter_enabled' => ['required', 'boolean'],
            'payment_icons_enabled' => ['required', 'boolean'],
            'background_color' => ['required', 'string', 'max:20'],
            'text_color' => ['required', 'string', 'max:20'],
            'columns' => ['required', 'integer', 'min:1', 'max:6'],
            'show_social_links' => ['required', 'boolean'],
        ];
    }
}
