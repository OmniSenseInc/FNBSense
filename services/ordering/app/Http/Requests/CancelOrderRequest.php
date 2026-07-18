<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi pembatalan order oleh kasir.
 *
 * `reason` opsional (alasan operasional: salah pesan, pelanggan batal). Otorisasi
 * ditegakkan di middleware + helper outletId(), jadi authorize() true.
 */
class CancelOrderRequest extends FormRequest
{
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
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
