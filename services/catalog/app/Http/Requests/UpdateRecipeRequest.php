<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Ganti product/ingredient = resep baru; update hanya takaran.
        return [
            'qty_per_unit' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
