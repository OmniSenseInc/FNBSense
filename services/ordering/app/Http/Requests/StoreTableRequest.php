<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableRequest extends FormRequest
{
    /** Otorisasi ditangani middleware 'jwt' + 'role:owner' di route. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Catatan: `tenant_id`/`outlet_id` sengaja TIDAK divalidasi di sini karena
     * tidak boleh datang dari body sama sekali — keduanya diambil dari klaim
     * JWT di controller. Kunci di luar daftar ini tidak ikut validated().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => [
                'required',
                'string',
                'max:50',
                // Unik per outlet, bukan global: "Meja 1" boleh ada di tiap outlet.
                // Dicek di sini supaya duplikat jadi 422 yang menjelaskan, bukan
                // 500 mentah dari unique constraint DB.
                Rule::unique('tables', 'label')
                    ->where('tenant_id', $this->attributes->get('tenant_id'))
                    ->where('outlet_id', $this->attributes->get('outlet_id')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.unique' => 'Meja dengan label ini sudah ada di outlet Anda.',
        ];
    }
}
