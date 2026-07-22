<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tutup shift: butuh kas fisik yang dihitung di laci. Sistem yang hitung selisih
 * vs expected (opening + penjualan cash) — kasir tak mengirim selisih.
 */
class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi via middleware jwt+role
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'closing_cash' => ['required', 'integer', 'min:0'],
        ];
    }
}
