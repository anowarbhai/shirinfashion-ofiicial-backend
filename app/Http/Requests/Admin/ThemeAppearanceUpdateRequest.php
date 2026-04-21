<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemeAppearanceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'favicon_url' => ['nullable', 'string', 'max:2048'],
            'company_name' => ['required', 'string', 'max:255'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'company_details' => ['nullable', 'string', 'max:5000'],
            'contact.phone' => ['nullable', 'string', 'max:50'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.address' => ['nullable', 'string', 'max:500'],
            'contact.hotline' => ['nullable', 'string', 'max:50'],
            'contact.whatsapp' => ['nullable', 'string', 'max:50'],
            'social_links.facebook' => ['nullable', 'url', 'max:255'],
            'social_links.instagram' => ['nullable', 'url', 'max:255'],
            'social_links.youtube' => ['nullable', 'url', 'max:255'],
            'social_links.linkedin' => ['nullable', 'url', 'max:255'],
            'social_links.tiktok' => ['nullable', 'url', 'max:255'],
            'social_links.twitter' => ['nullable', 'url', 'max:255'],
            'social_links.custom' => ['nullable', 'array'],
            'social_links.custom.*.label' => ['required_with:social_links.custom', 'string', 'max:80'],
            'social_links.custom.*.url' => ['required_with:social_links.custom', 'url', 'max:255'],
            'colors.primary' => ['required', 'string', 'max:20'],
            'colors.secondary' => ['required', 'string', 'max:20'],
            'colors.accent' => ['required', 'string', 'max:20'],
            'colors.background' => ['required', 'string', 'max:20'],
            'colors.text' => ['required', 'string', 'max:20'],
            'fonts.site_font_family' => ['required', 'string', 'max:120'],
            'fonts.site_font_url' => ['nullable', 'string', 'max:500'],
            'fonts.default_font_size' => ['required', 'string', 'max:20'],
            'headings.h1.size' => ['required', 'string', 'max:20'],
            'headings.h1.weight' => ['required', 'string', 'max:20'],
            'headings.h1.color' => ['required', 'string', 'max:20'],
            'headings.h2.size' => ['required', 'string', 'max:20'],
            'headings.h2.weight' => ['required', 'string', 'max:20'],
            'headings.h2.color' => ['required', 'string', 'max:20'],
            'headings.h3.size' => ['required', 'string', 'max:20'],
            'headings.h3.weight' => ['required', 'string', 'max:20'],
            'headings.h3.color' => ['required', 'string', 'max:20'],
            'body.font_family' => ['required', 'string', 'max:120'],
            'body.font_size' => ['required', 'string', 'max:20'],
            'body.line_height' => ['required', 'string', 'max:20'],
            'body.font_weight' => ['required', 'string', 'max:20'],
            'body.color' => ['required', 'string', 'max:20'],
        ];
    }
}
