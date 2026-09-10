<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                // Abaikan baris sendiri saat cek unik.
                Rule::unique('ingredients', 'name')
                    ->where('tenant_id', $this->attributes->get('tenant_id'))
                    ->ignore($this->route('id')),
            ],
            'unit' => ['sometimes', 'required', Rule::in(['g', 'ml', 'pcs'])],
            'cost_per_unit' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
