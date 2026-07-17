<?php

namespace App\Services;

use App\Exceptions\CatalogUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke service Catalog — satu-satunya sumber harga & nama produk.
 *
 * Ordering TIDAK PERNAH mempercayai harga dari klien. Saat order dibuat, harga
 * di-snapshot dari sini. Kelas ini hanya MENGAMBIL data mentah; konversi ke
 * integer rupiah dilakukan di batas berikutnya (kalkulator order), sekali.
 *
 * Catatan cakupan: /api/menu Catalog hanya memuat produk `is_available` yang
 * berada di kategori aktif. Produk di luar itu tidak muncul di peta -> secara
 * struktural tak bisa dipesan. Itu justru yang menegakkan "hanya produk yang
 * benar-benar dijual bisa masuk order".
 */
class CatalogClient
{
    /**
     * Peta produk milik satu tenant: [product_id => ['name' => string, 'price' => mixed]].
     *
     * price dikembalikan APA ADANYA dari Catalog (string desimal) — pembulatan
     * ke rupiah bulat bukan tugas klien, melainkan kalkulator saat snapshot.
     *
     * @return array<string, array{name: string, price: mixed}>
     *
     * @throws CatalogUnavailableException Catalog tak terhubung / balas non-2xx.
     */
    public function productsForTenant(string $tenantId): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->get('/api/menu', ['tenant' => $tenantId]);
        } catch (ConnectionException $e) {
            // Timeout / DNS / koneksi ditolak -> Catalog dianggap tak tersedia.
            throw new CatalogUnavailableException('Catalog tidak dapat dihubungi.', 0, $e);
        }

        if ($response->failed()) {
            throw new CatalogUnavailableException(
                "Catalog membalas status {$response->status()}."
            );
        }

        // Status 200 belum menjamin body waras: proxy bisa balas halaman HTML
        // error dengan 200, atau Catalog bug mengirim `data` bukan array. Kalau
        // dibiarkan, json('data', []) diam-diam jadi peta kosong (seolah tenant
        // tak punya produk) atau indexProducts() kena TypeError -> 500 mentah.
        // Dua-duanya melanggar kontrak "Catalog bermasalah = 503, bukan diam".
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new CatalogUnavailableException('Catalog membalas data yang tidak dikenali.');
        }

        return $this->indexProducts($data);
    }

    /**
     * Ratakan struktur menu (kategori -> produk bersarang) menjadi peta produk
     * ber-key id, membuang bungkus kategori yang tak dibutuhkan Ordering.
     *
     * @param  array<int, mixed>  $categories
     * @return array<string, array{name: string, price: mixed}>
     */
    private function indexProducts(array $categories): array
    {
        $products = [];

        foreach ($categories as $category) {
            foreach ($category['products'] ?? [] as $product) {
                if (! isset($product['id'])) {
                    continue;
                }

                $products[$product['id']] = [
                    'name' => $product['name'] ?? '',
                    'price' => $product['price'] ?? null,
                ];
            }
        }

        return $products;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.catalog.base_url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('services.catalog.timeout', 3);
    }
}
