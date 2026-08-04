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
     * Nama bahan untuk sekumpulan ingredient_id — dipakai layar stok kasir.
     *
     * Catatan nama kelas: berkas ini bernama "RecipeClient" karena resep yang
     * lebih dulu ada. Nama bahan tinggal di sini, bukan di kelas kedua, supaya
     * baseUrl/timeout/token/penanganan galat cuma punya satu tempat.
     *
     * Beda watak dari recipesForProducts() dan ini disengaja: yang itu di jalur
     * uang, jadi Catalog mati harus MELEDAK supaya pesannya di-requeue dan stok
     * tak dipotong salah. Yang ini cuma hiasan layar — nama yang hilang membuat
     * kasir melihat UUID, sedangkan melempar galat membuat dia tak melihat stok
     * sama sekali. Karena itu pemanggilnya yang menangkap, dan galatnya tetap
     * dilempar dari sini supaya keputusan itu diambil sadar, bukan diwarisi.
     *
     * @param  array<int, string>  $ingredientIds  daftar id unik
     * @return array<string, string> peta ingredient_id => name. Id yang tak
     *                               dikenal Catalog tak muncul di peta.
     *
     * @throws CatalogUnavailableException Catalog tak terhubung / balas non-2xx / data tak dikenali.
     */
    public function ingredientNames(string $tenantId, array $ingredientIds): array
    {
        if ($ingredientIds === []) {
            return [];
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->withHeaders(['X-Service-Token' => (string) config('services.catalog.service_token')])
                ->get('/api/ingredient', [
                    'tenant' => $tenantId,
                    'ids' => implode(',', $ingredientIds),
                ]);
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

        $peta = [];
        foreach ($data as $row) {
            // Baris tanpa id atau tanpa nama string di-skip, bukan menumbangkan
            // seluruh daftar: satu baris rusak paling jauh membuat satu bahan
            // tampil sebagai UUID.
            if (! isset($row['id']) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            $peta[(string) $row['id']] = $row['name'];
        }

        return $peta;
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
