<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi penyaring daftar pesanan kasir.
 *
 * Otorisasi ditegakkan middleware 'role:cashier,owner' dan helper outletId(),
 * bukan di sini -> authorize() true. Penyaring di bawah TIDAK pernah menentukan
 * outlet mana yang terbaca; itu selalu datang dari klaim JWT di controller.
 *
 * Ketiganya query string, dan query string datang dari luar. `paid_since`
 * khususnya berakhir sebagai operand perbandingan tanggal di SQL: nilai yang
 * tak berbentuk tanggal membuat MySQL membandingkan sampah, dan hasilnya bukan
 * error melainkan daftar yang diam-diam salah isi.
 */
class ListOrdersRequest extends FormRequest
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
            // Enum, bukan string bebas: status yang salah ketik dulu membalas
            // daftar KOSONG, yang di layar tak bisa dibedakan dari "memang
            // belum ada pesanan".
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'table_id' => ['sometimes', 'uuid'],
            'paid_since' => ['sometimes', 'date'],
        ];
    }
}
