<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Daftar-izin kolom produk untuk menu PUBLIK (dibaca HP pelanggan, tanpa auth).
 *
 * Kenapa ada: sebelum ini controller mengembalikan model Eloquent mentah, jadi
 * setiap kolom baru di tabel products otomatis ikut terkirim ke publik. Itu
 * aman-aman saja hari ini, tapi F7b akan menambahkan harga modal (COGS) ke
 * tabel yang sama — dan HPP tidak boleh sampai ke HP pelanggan.
 *
 * Bentuk daftar-izin dipilih, BUKAN daftar-larangan ($hidden): daftar-larangan
 * gagal secara diam-diam karena orang harus ingat menambahkan kolom baru ke
 * sana. Daftar-izin gagal dengan berisik — kolom baru tidak muncul sampai
 * seseorang sengaja menuliskannya di sini.
 */
class MenuProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'image_url' => $this->image_url,
            // Bahannya tak cukup untuk satu porsi di outlet yang diminta.
            // Selalu ada, meski `?outlet=` tak dikirim — waktu itu nilainya
            // false, yang berarti "kami tak tahu", bukan "pasti ada". Medan
            // yang kadang muncul kadang tidak membuat pembacanya menebak.
            //
            // Beda dari is_available: itu saklar owner ("Habiskan"), dan produk
            // yang dimatikannya tak pernah sampai ke sini sama sekali.
            'is_out_of_stock' => (bool) $this->is_out_of_stock,
        ];
    }
}
