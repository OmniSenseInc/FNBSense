<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nama unik per tenant (cocok dengan unique DB) -> anti duplikat bahan.
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ingredients', 'name')->where('tenant_id', $this->attributes->get('tenant_id')),
            ],
            'unit' => ['required', Rule::in(['g', 'ml', 'pcs'])],
        ];
    }
}
