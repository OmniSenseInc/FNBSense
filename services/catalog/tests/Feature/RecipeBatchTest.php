<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endpoint internal service-to-service GET /api/recipe (auth X-Service-Token).
 * Dikonsumsi Inventory untuk memotong stok. Jalur uang -> scoping wajib bergigi.
 */
class RecipeBatchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-secret-catalog'; // sama dengan phpunit.xml CATALOG_SERVICE_TOKEN.

    /** @return array<string, string> */
    private function serviceHeader(): array
    {
        return ['X-Service-Token' => self::TOKEN];
    }

    private function buatResep(string $tenantId, string $unit = 'ml', float $qty = 150): Recipe
    {
        $produk = Product::create(['tenant_id' => $tenantId, 'name' => 'Latte '.Str::random(4), 'price' => 25000]);
        $bahan = Ingredient::create(['tenant_id' => $tenantId, 'name' => 'Bahan '.Str::random(4), 'unit' => $unit]);

        return Recipe::create([
            'tenant_id' => $tenantId,
            'product_id' => $produk->id,
            'ingredient_id' => $bahan->id,
            'qty_per_unit' => $qty,
        ]);
    }

    public function test_balikin_resep_batch_dengan_unit(): void
    {
        $tenantId = (string) Str::uuid();
        $resep = $this->buatResep($tenantId, 'ml', 150);

        $this->withHeaders($this->serviceHeader())
            ->getJson("/api/recipe?tenant={$tenantId}&products={$resep->product_id}")
            ->assertOk()
            ->assertJsonPath('0.product_id', $resep->product_id)
            ->assertJsonPath('0.ingredients.0.ingredient_id', $resep->ingredient_id)
            ->assertJsonPath('0.ingredients.0.unit', 'ml')
            ->assertJsonPath('0.ingredients.0.qty_per_unit', '150.000');
    }

    public function test_produk_tanpa_resep_tidak_muncul(): void
    {
        $tenantId = (string) Str::uuid();
        $punyaResep = $this->buatResep($tenantId);
        $tanpaResep = Product::create(['tenant_id' => $tenantId, 'name' => 'Polos', 'price' => 1000]);

        $this->withHeaders($this->serviceHeader())
            ->getJson("/api/recipe?tenant={$tenantId}&products={$punyaResep->product_id},{$tanpaResep->id}")
            ->assertOk()
            ->assertJsonCount(1) // hanya produk yang punya resep.
            ->assertJsonPath('0.product_id', $punyaResep->product_id);
    }

    public function test_tidak_bocor_resep_tenant_lain(): void
    {
        $tenantB = (string) Str::uuid();
        $resepB = $this->buatResep($tenantB);

        // Minta pakai tenant A (acak) tapi product_id milik B -> harus kosong.
        $this->withHeaders($this->serviceHeader())
            ->getJson('/api/recipe?tenant='.Str::uuid()."&products={$resepB->product_id}")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $tenantId = (string) Str::uuid();
        $this->getJson("/api/recipe?tenant={$tenantId}&products=".Str::uuid())
            ->assertUnauthorized();
    }

    public function test_token_salah_ditolak_401(): void
    {
        $tenantId = (string) Str::uuid();
        $this->withHeaders(['X-Service-Token' => 'salah'])
            ->getJson("/api/recipe?tenant={$tenantId}&products=".Str::uuid())
            ->assertUnauthorized();
    }

    public function test_tenant_wajib_422(): void
    {
        $this->withHeaders($this->serviceHeader())
            ->getJson('/api/recipe?products='.Str::uuid())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant');
    }
}
