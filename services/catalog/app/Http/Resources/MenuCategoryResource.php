<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Daftar-izin kolom kategori untuk menu PUBLIK. Lihat MenuProductResource
 * untuk alasan lengkap kenapa daftar-izin, bukan daftar-larangan.
 *
 * tenant_id sengaja TIDAK ikut: pelanggan sudah mengirimnya sendiri lewat
 * query param, jadi mengembalikannya nol guna. is_active juga tidak — kategori
 * nonaktif memang tak pernah sampai ke sini.
 */
class MenuCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // whenLoaded, bukan $this->products: kalau suatu saat resource ini
            // dipakai tanpa eager-load, ini mencegah N+1 query diam-diam.
            'products' => MenuProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
