<?php

namespace App\Services;

use App\Exceptions\CatalogUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke Catalog — satu-satunya sumber resep (BOM) buat potong stok.
 *
 * Consumer jalan dari event (tak punya JWT user), jadi auth ke Catalog pakai
 * shared-secret header X-Service-Token (harus sama dgn CATALOG_SERVICE_TOKEN di
 * sisi Catalog). Endpoint balikin BARE array (bukan bungkus `data`):
 *   [{ product_id, ingredients: [{ ingredient_id, qty_per_unit, unit }] }]
 * Produk tanpa resep TAK muncul di hasil -> consumer perlakukan sbg "unmapped".
 */
class CatalogRecipeClient
{
    /**
     * Resep batch utk sekumpulan produk milik satu tenant.
     *
     * @param  array<int, string>  $productIds  daftar product_id unik
     * @return array<string, array<int, array{ingredient_id: string, qty_per_unit: mixed, unit: ?string}>>
     *         peta product_id => daftar ingredient. Produk unmapped tak ada di peta.
     *
     * @throws CatalogUnavailableException Catalog tak terhubung / balas non-2xx / data tak dikenali.
     */
    public function recipesForProducts(string $tenantId, array $productIds): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->withHeaders(['X-Service-Token' => (string) config('services.catalog.service_token')])
                ->get('/api/recipe', [
                    'tenant' => $tenantId,
                    'products' => implode(',', $productIds),
                ]);
        } catch (ConnectionException $e) {
            // Timeout / DNS / koneksi ditolak -> Catalog dianggap tak tersedia.
            throw new CatalogUnavailableException('Catalog tidak dapat dihubungi.', 0, $e);
        }

        // non-2xx (termasuk 401 token salah) -> perlakukan sbg tak-tersedia => nack/retry.
        // ponytail: 401 = salah konfigurasi token, bukan transient; ideal ke DLQ-after-N,
        //           tapi hitung-redelivery belum ada. Utang F4c/pengerasan.
        if ($response->failed()) {
            throw new CatalogUnavailableException("Catalog membalas status {$response->status()}.");
        }

        // 200 belum jamin body waras (proxy bisa balas HTML 200). Bukan array -> anggap
        // tak dikenali => retry, JANGAN diam-diam jadi "semua produk unmapped".
        $data = $response->json();
        if (! is_array($data)) {
            throw new CatalogUnavailableException('Catalog membalas data yang tidak dikenali.');
        }

        return $this->indexByProduct($data);
    }

    /**
     * Semua bahan milik satu tenant — dipakai layar stok untuk menampilkan bahan
     * yang BELUM pernah distok (belum punya baris saldo), supaya owner bisa
     * restock dari sana.
     *
     * Hiasan layar, bukan jalur uang — Catalog mati melempar
     * CatalogUnavailableException, dan pemanggil yang memutuskan apakah layar
     * jatuh atau menampilkan saldo tanpa daftar bahan.
     *
     * @return array<int, array{id: string, name: string, unit: ?string}>
     *
     * @throws CatalogUnavailableException Catalog tak terhubung / balas non-2xx / data tak dikenali.
     */
    public function ingredients(string $tenantId): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->withHeaders(['X-Service-Token' => (string) config('services.catalog.service_token')])
                ->get('/api/ingredient/all', ['tenant' => $tenantId]);
        } catch (ConnectionException $e) {
            throw new CatalogUnavailableException('Catalog tidak dapat dihubungi.', 0, $e);
        }

        if ($response->failed()) {
            throw new CatalogUnavailableException("Catalog membalas status {$response->status()}.");
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new CatalogUnavailableException('Catalog membalas data yang tidak dikenali.');
        }

        $hasil = [];
        foreach ($data as $row) {
            // Baris tanpa id atau tanpa nama di-skip, bukan menumbangkan daftar.
            if (! isset($row['id']) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            $hasil[] = [
                'id' => (string) $row['id'],
                'name' => $row['name'],
                'unit' => is_string($row['unit'] ?? null) ? $row['unit'] : null,
            ];
        }

        return $hasil;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<string, array<int, array{ingredient_id: string, qty_per_unit: mixed, unit: ?string}>>
     */
    private function indexByProduct(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            // Baris rusak (tanpa product_id / ingredients bukan array) di-skip, bukan
            // menumbangkan seluruh batch. Produk itu jadi unmapped (aman: skip+event).
            if (! isset($row['product_id']) || ! is_array($row['ingredients'] ?? null)) {
                continue;
            }

            $map[$row['product_id']] = $row['ingredients'];
        }

        return $map;
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
