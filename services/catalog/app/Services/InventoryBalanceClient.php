<?php

namespace App\Services;

use App\Exceptions\InventoryUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke Inventory — "berapa sisa bahan-bahan ini di outlet itu?".
 *
 * Dipakai `/api/menu` untuk menandai produk yang bahannya habis. Sengaja
 * memanggil `GET /api/balance` dan BUKAN `POST /api/availability`: gerbang itu
 * mengambil resep dengan memanggil balik Catalog, jadi Catalog->Inventory->
 * Catalog akan jadi lingkaran yang dipicu tiap pemindaian QR. Di sini
 * pembagiannya lurus — Inventory pemilik saldo, Catalog pemilik resep dan yang
 * menghitung.
 *
 * Auth shared-secret X-Service-Token; nilainya HARUS sama dengan
 * INVENTORY_SERVICE_TOKEN di sisi Inventory.
 */
class InventoryBalanceClient
{
    /**
     * Batas id per permintaan. HARUS <= MAX_IDS di BalanceController Inventory
     * (500) — kelebihannya dipotong DIAM-DIAM di sana, dan bahan yang saldonya
     * tak terbawa akan terbaca sebagai nol, artinya produknya ditandai habis
     * padahal gudang penuh.
     */
    private const CHUNK = 500;

    /**
     * Saldo bahan di satu outlet.
     *
     * @param  array<int, string>  $ingredientIds
     * @return array<string, float> peta ingredient_id => qty_on_hand. Bahan
     *                              tanpa baris saldo TAK muncul di peta —
     *                              pemanggil yang memutuskan itu nol.
     *
     * @throws InventoryUnavailableException
     */
    public function balances(string $tenantId, string $outletId, array $ingredientIds): array
    {
        $ids = array_values(array_unique($ingredientIds));

        if ($ids === []) {
            return [];
        }

        $peta = [];

        foreach (array_chunk($ids, self::CHUNK) as $potongan) {
            foreach ($this->minta($tenantId, $outletId, $potongan) as $baris) {
                $id = $baris['ingredient_id'] ?? null;
                $qty = $baris['qty_on_hand'] ?? null;

                // Baris rusak di-skip, bukan menumbangkan seluruh menu. Efeknya
                // bahan itu terbaca nol dan produknya ditandai habis — arah yang
                // sama dengan bahan yang memang belum pernah distok.
                if (! is_string($id) || ! is_numeric($qty)) {
                    continue;
                }

                $peta[$id] = (float) $qty;
            }
        }

        return $peta;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, mixed>
     *
     * @throws InventoryUnavailableException
     */
    private function minta(string $tenantId, string $outletId, array $ids): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->withHeaders(['X-Service-Token' => (string) config('services.inventory.service_token')])
                ->get('/api/balance', [
                    'tenant' => $tenantId,
                    'outlet' => $outletId,
                    'ids' => implode(',', $ids),
                ]);
        } catch (ConnectionException $e) {
            throw new InventoryUnavailableException('Inventory tidak dapat dihubungi.', 0, $e);
        }

        if ($response->failed()) {
            throw new InventoryUnavailableException("Inventory membalas status {$response->status()}.");
        }

        // 200 belum menjamin badannya waras (proxy bisa membalas HTML 200).
        // Bukan array -> jangan diam-diam jadi "semua bahan nol", yang akan
        // menandai SELURUH menu habis.
        $data = $response->json();

        if (! is_array($data)) {
            throw new InventoryUnavailableException('Inventory membalas data yang tidak dikenali.');
        }

        return $data;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.inventory.base_url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('services.inventory.timeout', 3);
    }
}
