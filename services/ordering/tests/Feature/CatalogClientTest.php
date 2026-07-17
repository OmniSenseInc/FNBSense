<?php

namespace Tests\Feature;

use App\Exceptions\CatalogUnavailableException;
use App\Services\CatalogClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogClientTest extends TestCase
{
    private function menuPayload(): array
    {
        return ['data' => [
            [
                'id' => (string) Str::uuid(),
                'name' => 'Kopi',
                'products' => [
                    ['id' => 'prod-1', 'name' => 'Espresso', 'price' => '18000.00', 'is_available' => true],
                    ['id' => 'prod-2', 'name' => 'Latte', 'price' => '25000.00', 'is_available' => true],
                ],
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Teh',
                'products' => [
                    ['id' => 'prod-3', 'name' => 'Teh Tarik', 'price' => '15000.00', 'is_available' => true],
                ],
            ],
        ]];
    }

    public function test_meratakan_menu_jadi_peta_produk_per_id(): void
    {
        Http::fake(['*/api/menu*' => Http::response($this->menuPayload(), 200)]);

        $products = (new CatalogClient)->productsForTenant((string) Str::uuid());

        $this->assertCount(3, $products);
        $this->assertSame('Espresso', $products['prod-1']['name']);
        $this->assertSame('Teh Tarik', $products['prod-3']['name']);
    }

    public function test_harga_dikembalikan_apa_adanya_tanpa_konversi(): void
    {
        Http::fake(['*/api/menu*' => Http::response($this->menuPayload(), 200)]);

        $products = (new CatalogClient)->productsForTenant((string) Str::uuid());

        // Klien tidak boleh membulatkan/mengubah harga — itu tugas kalkulator.
        $this->assertSame('18000.00', $products['prod-1']['price']);
    }

    public function test_tenant_dikirim_sebagai_query_param(): void
    {
        Http::fake(['*/api/menu*' => Http::response($this->menuPayload(), 200)]);
        $tenantId = (string) Str::uuid();

        (new CatalogClient)->productsForTenant($tenantId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'tenant='.$tenantId)
            && str_contains($request->url(), '/api/menu'));
    }

    public function test_menu_kosong_menghasilkan_peta_kosong(): void
    {
        Http::fake(['*/api/menu*' => Http::response(['data' => []], 200)]);

        $this->assertSame([], (new CatalogClient)->productsForTenant((string) Str::uuid()));
    }

    public function test_catalog_balas_error_melempar_exception(): void
    {
        Http::fake(['*/api/menu*' => Http::response('', 500)]);

        $this->expectException(CatalogUnavailableException::class);

        (new CatalogClient)->productsForTenant((string) Str::uuid());
    }

    public function test_catalog_tak_terhubung_melempar_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(CatalogUnavailableException::class);

        (new CatalogClient)->productsForTenant((string) Str::uuid());
    }

    /** Status 200 tapi body HTML (mis. halaman error proxy) -> jangan diam, lempar. */
    public function test_body_bukan_json_melempar_exception(): void
    {
        Http::fake(['*/api/menu*' => Http::response('<html>maintenance</html>', 200)]);

        $this->expectException(CatalogUnavailableException::class);

        (new CatalogClient)->productsForTenant((string) Str::uuid());
    }

    /** JSON valid tapi `data` bukan array -> jangan 500 mentah, lempar exception. */
    public function test_data_bukan_array_melempar_exception(): void
    {
        Http::fake(['*/api/menu*' => Http::response(['data' => 'bukan-array'], 200)]);

        $this->expectException(CatalogUnavailableException::class);

        (new CatalogClient)->productsForTenant((string) Str::uuid());
    }
}
