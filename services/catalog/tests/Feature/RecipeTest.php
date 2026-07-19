<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: Product, 2: Ingredient}
     */
    private function tenantProdukBahan(): array
    {
        $tenantId = (string) Str::uuid();
        $produk = Product::create(['tenant_id' => $tenantId, 'name' => 'Latte', 'price' => 25000]);
        $bahan = Ingredient::create(['tenant_id' => $tenantId, 'name' => 'Susu', 'unit' => 'ml']);

        return [$tenantId, $produk, $bahan];
    }

    public function test_owner_bisa_membuat_resep(): void
    {
        [$tenantId, $produk, $bahan] = $this->tenantProdukBahan();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/recipes', [
                'product_id' => $produk->id,
                'ingredient_id' => $bahan->id,
                'qty_per_unit' => 150,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $tenantId);

        $this->assertDatabaseHas('recipes', [
            'product_id' => $produk->id,
            'ingredient_id' => $bahan->id,
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_tidak_bisa_pakai_produk_tenant_lain_422(): void
    {
        [$tenantId, , $bahan] = $this->tenantProdukBahan();
        $produkLain = Product::create(['tenant_id' => (string) Str::uuid(), 'name' => 'X', 'price' => 1000]);

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/recipes', [
                'product_id' => $produkLain->id,
                'ingredient_id' => $bahan->id,
                'qty_per_unit' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    public function test_tidak_bisa_pakai_bahan_tenant_lain_422(): void
    {
        [$tenantId, $produk] = $this->tenantProdukBahan();
        $bahanLain = Ingredient::create(['tenant_id' => (string) Str::uuid(), 'name' => 'Y', 'unit' => 'g']);

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/recipes', [
                'product_id' => $produk->id,
                'ingredient_id' => $bahanLain->id,
                'qty_per_unit' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ingredient_id');
    }

    public function test_bahan_duplikat_dalam_produk_ditolak_422(): void
    {
        [$tenantId, $produk, $bahan] = $this->tenantProdukBahan();
        Recipe::create([
            'tenant_id' => $tenantId,
            'product_id' => $produk->id,
            'ingredient_id' => $bahan->id,
            'qty_per_unit' => 100,
        ]);

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/recipes', [
                'product_id' => $produk->id,
                'ingredient_id' => $bahan->id,
                'qty_per_unit' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ingredient_id');
    }

    public function test_qty_nol_ditolak_validasi_422(): void
    {
        [$tenantId, $produk, $bahan] = $this->tenantProdukBahan();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/recipes', [
                'product_id' => $produk->id,
                'ingredient_id' => $bahan->id,
                'qty_per_unit' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qty_per_unit');
    }

    public function test_check_db_menolak_qty_nol_walau_validasi_dilewati(): void
    {
        [$tenantId, $produk, $bahan] = $this->tenantProdukBahan();

        // Bypass FormRequest: buktikan CHECK constraint di DB yang jadi benteng terakhir.
        $this->expectException(QueryException::class);
        Recipe::create([
            'tenant_id' => $tenantId,
            'product_id' => $produk->id,
            'ingredient_id' => $bahan->id,
            'qty_per_unit' => 0,
        ]);
    }

    public function test_index_filter_per_produk(): void
    {
        [$tenantId, $produk, $bahan] = $this->tenantProdukBahan();
        $produk2 = Product::create(['tenant_id' => $tenantId, 'name' => 'Mocha', 'price' => 27000]);
        Recipe::create(['tenant_id' => $tenantId, 'product_id' => $produk->id, 'ingredient_id' => $bahan->id, 'qty_per_unit' => 150]);
        Recipe::create(['tenant_id' => $tenantId, 'product_id' => $produk2->id, 'ingredient_id' => $bahan->id, 'qty_per_unit' => 200]);

        $this->withHeaders($this->authHeaders($tenantId))
            ->getJson("/api/recipes?product={$produk->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $produk->id);
    }

    public function test_index_hanya_menampilkan_resep_milik_tenant(): void
    {
        [$tenantId, $produk, $bahan] = $this->tenantProdukBahan();
        Recipe::create(['tenant_id' => $tenantId, 'product_id' => $produk->id, 'ingredient_id' => $bahan->id, 'qty_per_unit' => 150]);

        [$tenantLain, $produkLain, $bahanLain] = $this->tenantProdukBahan();
        Recipe::create(['tenant_id' => $tenantLain, 'product_id' => $produkLain->id, 'ingredient_id' => $bahanLain->id, 'qty_per_unit' => 99]);

        $this->withHeaders($this->authHeaders($tenantId))
            ->getJson('/api/recipes')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->postJson('/api/recipes', [])->assertUnauthorized();
    }
}
