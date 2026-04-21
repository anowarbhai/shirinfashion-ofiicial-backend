<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeMenuStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', 'unique:storefront_menus,slug'],
            'location' => ['nullable', Rule::in(['header_menu', 'footer_menu_1', 'footer_menu_2'])],
            'is_active' => ['required', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable'],
            'items.*.parent_id' => ['nullable'],
            'items.*.title' => ['required', 'string', 'max:120'],
            'items.*.type' => ['required', Rule::in(['custom_url', 'page', 'category', 'product'])],
            'items.*.reference_id' => ['nullable', 'integer'],
            'items.*.url' => ['nullable', 'string', 'max:2048'],
            'items.*.target_blank' => ['required', 'boolean'],
            'items.*.css_class' => ['nullable', 'string', 'max:120'],
            'items.*.icon' => ['nullable', 'string', 'max:120'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
            'items.*.is_active' => ['required', 'boolean'],
        ];
    }
}
