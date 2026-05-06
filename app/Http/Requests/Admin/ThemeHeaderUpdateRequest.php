<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeHeaderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'active_style' => ['required', Rule::in(['style-1', 'style-2', 'style-3'])],
            'sticky' => ['required', 'boolean'],
            'show_top_bar' => ['required', 'boolean'],
            'show_search' => ['required', 'boolean'],
            'show_cart' => ['required', 'boolean'],
            'show_account' => ['required', 'boolean'],
            'show_wishlist' => ['required', 'boolean'],
            'show_announcement_bar' => ['required', 'boolean'],
            'announcement_text' => ['nullable', 'string', 'max:255'],
            'announcement_expires_at' => ['nullable', 'string', 'max:40'],
            'background_color' => ['required', 'string', 'max:20'],
            'menu_alignment' => ['required', Rule::in(['left', 'center', 'right'])],
            'logo_position' => ['required', Rule::in(['left', 'center'])],
            'mobile_behavior' => ['required', Rule::in(['drawer', 'bottom-nav'])],
        ];
    }
}
