<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CatalogUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Klien ke endpoint harga pokok Catalog. Beda dari CatalogClient (menu) dan
 * PromotionClient (promo): yang ini BEST-EFFORT. Kalau gagal, HPP dianggap 0 —
 * order TETAP jalan, cuma laporan margin yang (sementara) melambung. Pemanggil
 * (OrderController) yang menangkap pengecualiannya dan melanjutkan dengan 0.
 */
class ProductCostClient
{
    /**
     * Peta product_id => unit_cost (integer rupiah) per SATU unit produk.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, int>
     *
     * @throws CatalogUnavailableException Catalog tak terhubung / balas non-2xx / bentuk salah.
     */
    public function unitCosts(string $tenantId, array $productIds): array
    {
        $serviceToken = (string) config('services.catalog.internal_token');
        if ($serviceToken === '') {
            throw new CatalogUnavailableException('Service token Catalog belum dikonfigurasi.');
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout($this->timeout())
                ->acceptJson()
                ->withHeader('X-Service-Token', $serviceToken)
                ->post('/api/internal/products/cost', [
                    'tenant_id' => $tenantId,
                    'product_ids' => array_values($productIds),
                ]);
        } catch (ConnectionException $e) {
            throw new CatalogUnavailableException('Catalog tidak dapat menghitung harga pokok.', 0, $e);
        }

        if ($response->failed()) {
            throw new CatalogUnavailableException("Harga pokok membalas status {$response->status()}.");
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new CatalogUnavailableException('Respons harga pokok tidak valid.');
        }

        // Hanya nilai integer tak-negatif yang dipercaya; selebihnya 0. Produk
        // yang tak muncul di peta (tak ada resep) otomatis 0 lewat default.
        $costs = [];
        foreach ($productIds as $id) {
            $value = $data[$id] ?? 0;
            $costs[$id] = (is_int($value) && $value >= 0) ? $value : 0;
        }

        return $costs;
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
