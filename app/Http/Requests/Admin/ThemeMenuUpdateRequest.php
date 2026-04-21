<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class ThemeMenuUpdateRequest extends ThemeMenuStoreRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $menu = $this->route('menu');
        $menuId = is_object($menu) ? $menu->id : $menu;

        $rules['slug'] = [
            'required',
            'string',
            'max:120',
            'alpha_dash',
            Rule::unique('storefront_menus', 'slug')->ignore($menuId),
        ];

        return $rules;
    }
}
