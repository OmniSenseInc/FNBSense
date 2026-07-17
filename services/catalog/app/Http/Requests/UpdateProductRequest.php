<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('categories', 'id')->where('tenant_id', $this->attributes->get('tenant_id')),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
