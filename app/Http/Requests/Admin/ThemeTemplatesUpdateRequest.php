<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ThemeTemplatesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'home' => ['required', Rule::in(['classic', 'campaign', 'editorial', 'runway', 'compact'])],
            'shop' => ['required', Rule::in(['classic', 'sidebar', 'compact', 'visual'])],
            'product' => ['required', Rule::in(['classic', 'conversion', 'minimal', 'review-focused'])],
            'about' => ['required', Rule::in(['classic', 'story', 'brand', 'minimal'])],
            'contact' => ['required', Rule::in(['classic', 'split', 'support', 'minimal'])],
            'blog_list' => ['required', Rule::in(['classic', 'magazine', 'minimal', 'grid'])],
            'blog_detail' => ['required', Rule::in(['classic', 'editorial', 'minimal', 'feature'])],
        ];
    }
}
