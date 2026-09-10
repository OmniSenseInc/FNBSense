<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoint internal POST /api/internal/products/cost (auth X-Service-Token).
 * Dihitung Ordering saat order dibuat untuk me-snapshot HPP. Inti: HPP per
 * produk = Σ(takaran resep × harga beli bahan), integer rupiah, 0 kalau bahan
 * belum dihargai / produk tak punya resep.
 */
class ProductCostTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-catalog'; // sama dengan phpunit.xml CATALOG_SERVICE_TOKEN.

    /** @return array<string, string> */
    private function serviceHeader(): array
    {
        return ['X-Service-Token' => self::TOKEN];
    }

    public function test_hitung_hpp_dari_resep_dan_harga_bahan(): void
    {
        $tenantId = (string) Str::uuid();
        $produk = Product::create(['tenant_id' => $tenantId, 'name' => 'Kopi Susu', 'price' => 25000]);

        $kopi = Ingredient::create(['tenant_id' => $tenantId, 'name' => 'Kopi', 'unit' => 'g', 'cost_per_unit' => 150]);
        $susu = Ingredient::create(['tenant_id' => $tenantId, 'name' => 'Susu', 'unit' => 'ml', 'cost_per_unit' => 20]);

        Recipe::create(['tenant_id' => $tenantId, 'product_id' => $produk->id, 'ingredient_id' => $kopi->id, 'qty_per_unit' => 18]);
        Recipe::create(['tenant_id' => $tenantId, 'product_id' => $produk->id, 'ingredient_id' => $susu->id, 'qty_per_unit' => 120]);

        // 18 × 150 = 2700; 120 × 20 = 2400 -> total 5100.
        $this->withHeaders($this->serviceHeader())
            ->postJson('/api/internal/products/cost', [
                'tenant_id' => $tenantId,
                'product_ids' => [$produk->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.'.$produk->id, 5100);
    }

    public function test_produk_tanpa_resep_hpp_nol(): void
    {
        $tenantId = (string) Str::uuid();
        $produk = Product::create(['tenant_id' => $tenantId, 'name' => 'Polos', 'price' => 1000]);

        $this->withHeaders($this->serviceHeader())
            ->postJson('/api/internal/products/cost', [
                'tenant_id' => $tenantId,
                'product_ids' => [$produk->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.'.$produk->id, 0);
    }

    public function test_bahan_tanpa_harga_dihitung_nol(): void
    {
        $tenantId = (string) Str::uuid();
        $produk = Product::create(['tenant_id' => $tenantId, 'name' => 'X', 'price' => 1000]);
        // Bahan tanpa cost_per_unit (default 0) -> HPP 0.
        $bahan = Ingredient::create(['tenant_id' => $tenantId, 'name' => 'B', 'unit' => 'g']);
        Recipe::create(['tenant_id' => $tenantId, 'product_id' => $produk->id, 'ingredient_id' => $bahan->id, 'qty_per_unit' => 50]);

        $this->withHeaders($this->serviceHeader())
            ->postJson('/api/internal/products/cost', [
                'tenant_id' => $tenantId,
                'product_ids' => [$produk->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.'.$produk->id, 0);
    }

    public function test_tidak_bocor_hpp_tenant_lain(): void
    {
        $tenantB = (string) Str::uuid();
        $produkB = Product::create(['tenant_id' => $tenantB, 'name' => 'Rahasia B', 'price' => 1000]);
        $bahanB = Ingredient::create(['tenant_id' => $tenantB, 'name' => 'Bahan B', 'unit' => 'g', 'cost_per_unit' => 999]);
        Recipe::create(['tenant_id' => $tenantB, 'product_id' => $produkB->id, 'ingredient_id' => $bahanB->id, 'qty_per_unit' => 10]);

        // Minta pakai tenant lain tapi id milik B -> HPP 0 (resep tak terlihat).
        $this->withHeaders($this->serviceHeader())
            ->postJson('/api/internal/products/cost', [
                'tenant_id' => (string) Str::uuid(),
                'product_ids' => [$produkB->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.'.$produkB->id, 0);
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->postJson('/api/internal/products/cost', [
            'tenant_id' => (string) Str::uuid(),
            'product_ids' => [(string) Str::uuid()],
        ])->assertUnauthorized();
    }

    public function test_product_ids_wajib_422(): void
    {
        $this->withHeaders($this->serviceHeader())
            ->postJson('/api/internal/products/cost', ['tenant_id' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_ids');
    }
}
