<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi body POST /api/cashier/orders (kasir buat order POS).
 *
 * Bedanya dari StoreOrderRequest (customer): tenant/outlet dari JWT (bukan
 * qr_token), dan meja dipilih eksplisit (table_id) untuk dine-in. Harga/total
 * TIDAK PERNAH diterima dari client — dihitung server.
 */
class StoreCashierOrderRequest extends FormRequest
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
            'order_type' => ['required', Rule::enum(OrderType::class)],
            // Nama pelanggan boleh kosong (walk-in tak selalu menyebut nama).
            'customer_name' => ['nullable', 'string', 'max:100'],
            // Meja hanya untuk dine-in; takeaway tanpa meja. Keberadaan meja
            // aktif diperiksa di controller (404 kalau tak ada), bukan di sini.
            'table_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'uuid'],
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
