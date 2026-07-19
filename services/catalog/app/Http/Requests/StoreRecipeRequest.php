<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id');

        return [
            // product & ingredient wajib milik tenant yang sama (anti tempel lintas-tenant).
            'product_id' => [
                'required',
                'uuid',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'ingredient_id' => [
                'required',
                'uuid',
                Rule::exists('ingredients', 'id')->where('tenant_id', $tenantId),
                // Satu bahan hanya sekali per produk (cocok dengan unique DB).
                Rule::unique('recipes', 'ingredient_id')->where('product_id', $this->input('product_id')),
            ],
            'qty_per_unit' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
