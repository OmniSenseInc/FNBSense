<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi konfirmasi pembayaran oleh kasir.
 *
 * Otorisasi (harus kasir/owner + terikat outlet) ditegakkan di middleware
 * 'role:cashier,owner' dan helper outletId(), bukan di sini -> authorize() true.
 * Satu-satunya input yang sah dari kasir adalah CARA bayar; jumlah uang tak
 * pernah dari request — sudah di-snapshot saat order dibuat.
 */
class ConfirmPaymentRequest extends FormRequest
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
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ];
    }
}
