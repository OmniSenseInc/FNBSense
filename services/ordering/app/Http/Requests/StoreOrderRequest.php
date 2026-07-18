<?php

namespace App\Http\Requests;

use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi body POST /api/orders (endpoint customer publik).
 *
 * Body sengaja RAMPING. Yang TIDAK ada di sini dan alasannya (skrutini #1 & #5):
 * - harga / unit_price / total apa pun dari client -> DIABAIKAN DIAM-DIAM. Tidak
 *   divalidasi "harus sama harga Catalog", cukup tidak dibaca: harga di-snapshot
 *   server dari Catalog. Client tak pernah boleh menentukan uang.
 * - tenant_id / outlet_id -> diambil dari qr_token meja, bukan dari body.
 *
 * qr_token cuma dicek FORMAT di sini; keberadaan meja aktif diperiksa di
 * controller supaya token tak dikenal jadi 404 (bukan 422 "input salah").
 */
class StoreOrderRequest extends FormRequest
{
    /** Endpoint publik tanpa login — otorisasi ditangani rate limit, bukan token. */
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
            'qr_token' => ['required', 'string'],
            'order_type' => ['required', Rule::enum(OrderType::class)],
            'customer_name' => ['required', 'string', 'max:100'],
            // Batas 50 item/order (skrutini #6): anti order absurd & overflow.
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'uuid'],
            // qty 1..99 per item (skrutini #6).
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Order harus punya minimal satu item.',
            'items.max' => 'Terlalu banyak item dalam satu order.',
            'items.*.qty.max' => 'Jumlah per item maksimal 99.',
        ];
    }
}
