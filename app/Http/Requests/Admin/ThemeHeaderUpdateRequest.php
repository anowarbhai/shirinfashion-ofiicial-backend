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
            'active_style' => ['required', Rule::in(['style-1', 'style-2', 'style-3', 'style-4', 'style-5'])],
            'sticky' => ['required', 'boolean'],
            'sticky_on_banner' => ['required', 'boolean'],
            'show_top_bar' => ['required', 'boolean'],
            'show_search' => ['required', 'boolean'],
            'search_style' => ['sometimes', 'nullable', Rule::in(['popup', 'header_overlay'])],
            'show_cart' => ['required', 'boolean'],
            'show_account' => ['required', 'boolean'],
            'show_wishlist' => ['required', 'boolean'],
            'show_announcement_bar' => ['required', 'boolean'],
            'announcement_text' => ['nullable', 'string', 'max:255'],
            'announcement_expires_at' => ['nullable', 'string', 'max:40'],
            'announcement_bg_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'announcement_text_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'show_hot_offer_bar' => ['sometimes', 'nullable', 'boolean'],
            'hot_offer_badge' => ['sometimes', 'nullable', 'string', 'max:50'],
            'hot_offer_text' => ['sometimes', 'nullable', 'string', 'max:550'],
            'hot_offer_expires_at' => ['sometimes', 'nullable', 'string', 'max:40'],
            'hot_offer_bg_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'hot_offer_text_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'background_color' => ['required', 'string', 'max:20'],
            'text_color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'hover_color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'menu_alignment' => ['required', Rule::in(['left', 'center', 'right'])],
            'logo_position' => ['required', Rule::in(['left', 'center'])],
            'mobile_behavior' => ['required', Rule::in(['drawer', 'bottom-nav'])],
        ];
    }
}
