<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
    /** Otorisasi ditangani middleware 'jwt' + 'role:owner' di route. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                // ignore(id meja ini): tanpa itu, menyimpan meja tanpa mengubah
                // label-nya akan ditolak karena "bentrok" dengan dirinya sendiri.
                Rule::unique('tables', 'label')
                    ->where('tenant_id', $this->attributes->get('tenant_id'))
                    ->where('outlet_id', $this->attributes->get('outlet_id'))
                    ->ignore($this->route('id')),
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
