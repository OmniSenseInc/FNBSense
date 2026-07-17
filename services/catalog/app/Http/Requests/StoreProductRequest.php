<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // category_id wajib milik tenant yang sama (anti tempel lintas-tenant).
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('categories', 'id')->where('tenant_id', $this->attributes->get('tenant_id')),
            ],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
