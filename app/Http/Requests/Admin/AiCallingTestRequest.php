<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AiCallingTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'max:40'],
            'customer_name' => ['required', 'string', 'max:120'],
            'product_names' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'string', 'max:80'],
        ];
    }
}
