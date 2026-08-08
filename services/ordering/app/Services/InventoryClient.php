<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gerbang stok: "produk mana yang bahannya tak cukup?" — ditanyakan SEBELUM
 * order dibuat, bukan sesudah dibayar.
 *
 * FAIL-OPEN, dan ini keputusan sadar, bukan kelalaian.
 *
 * Kalau Inventory tak terjangkau, pesanan TETAP dibuat. Alasannya: sistem ini
 * sudah punya jaring pengaman di belakang — `order.paid` memotong stok dan
 * menerbitkan `inventory.shortfall` + `low_stock` kalau kurang (F4c/F4d,
 * terbukti E2E). Menutup gerbang saat pengawasnya mati berarti kafe berhenti
 * berjualan sepenuhnya gara-gara satu service yang tak memegang uang.
 *
 * Konsekuensinya jujur: selama Inventory mati, perilaku kembali persis seperti
 * sebelum gerbang ini ada. Karena itu kegagalannya dicatat sebagai WARNING dan
 * bukan ditelan diam-diam — kalau baris ini sering muncul, yang salah bukan
 * gerbangnya melainkan Inventory-nya.
 *
 * Kalau kelak kafe lebih memilih berhenti menjual daripada menjual yang tak
 * ada, satu-satunya yang perlu berubah ada di sini: lempar, jangan kembalikan
 * daftar kosong.
 */
class InventoryClient
{
    /**
     * @param  array<int, array{product_id: string, qty: int}>  $items
     * @return array<int, string> product_id yang bahannya tak cukup; kosong = semua bisa dibuat
     */
    public function unavailableProducts(string $tenantId, string $outletId, array $items): array
    {
        if ($items === []) {
            return [];
        }

        try {
            $response = Http::baseUrl((string) config('services.inventory.base_url'))
                ->timeout((int) config('services.inventory.timeout', 3))
                ->connectTimeout((int) config('services.inventory.timeout', 3))
                ->withHeaders(['X-Service-Token' => (string) config('services.inventory.service_token')])
                ->acceptJson()
                ->post('/api/availability', [
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'items' => $items,
                ]);
        } catch (ConnectionException $e) {
            return $this->menyerah('Inventory tak dapat dihubungi.', $e->getMessage());
        }

        if (! $response->successful()) {
            // 401 di sini berarti token salah/belum dipasang — gerbangnya mati
            // total tanpa satu pun gejala di layar. Justru itu yang harus
            // terbaca di log.
            return $this->menyerah('Inventory membalas non-2xx.', (string) $response->status());
        }

        $daftar = $response->json('data.unavailable');

        if (! is_array($daftar)) {
            return $this->menyerah('Bentuk balasan Inventory tak dikenali.', $response->body());
        }

        // Hanya string yang diterima: nilai lain tak akan pernah cocok dengan
        // product_id mana pun, dan meloloskannya membuat perbandingan di
        // pemanggil diam-diam selalu gagal.
        return array_values(array_filter($daftar, 'is_string'));
    }

    /** @return array<int, string> selalu kosong — lihat catatan fail-open di kepala kelas. */
    private function menyerah(string $sebab, string $rinci): array
    {
        Log::warning('ordering.inventory: gerbang stok dilewati.', [
            'sebab' => $sebab,
            'rinci' => $rinci,
        ]);

        return [];
    }
}
