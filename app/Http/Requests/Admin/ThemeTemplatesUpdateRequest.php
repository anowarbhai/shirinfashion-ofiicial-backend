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
            'home' => ['required', Rule::in(['classic', 'campaign', 'editorial', 'runway', 'compact', 'organic-luxe'])],
            'shop' => ['required', Rule::in(['classic', 'sidebar', 'compact', 'visual', 'organic-luxe'])],
            'product' => ['required', Rule::in(['classic', 'conversion', 'minimal', 'review-focused', 'organic-luxe'])],
            'about' => ['required', Rule::in(['classic', 'story', 'brand', 'minimal', 'organic-luxe'])],
            'contact' => ['required', Rule::in(['classic', 'split', 'support', 'minimal', 'organic-luxe'])],
            'blog_list' => ['required', Rule::in(['classic', 'magazine', 'minimal', 'grid'])],
            'blog_detail' => ['required', Rule::in(['classic', 'editorial', 'minimal', 'feature'])],
        ];
    }
}
