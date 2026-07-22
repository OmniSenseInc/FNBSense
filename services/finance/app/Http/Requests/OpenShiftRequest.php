<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buka shift: cuma butuh modal awal laci. tenant/outlet/kasir diambil dari JWT,
 * bukan body (anti-IDOR).
 */
class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi via middleware jwt+role
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // Rupiah = integer tak negatif. min:0 (bukan min:1): modal awal boleh 0.
        return [
            'opening_cash' => ['required', 'integer', 'min:0'],
        ];
    }
}
