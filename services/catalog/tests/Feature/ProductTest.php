<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    public function test_owner_bisa_membuat_produk_dengan_kategori_sendiri(): void
    {
        $tenantId = (string) Str::uuid();
        $kategori = Category::create(['tenant_id' => $tenantId, 'name' => 'Kopi']);

        $response = $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/products', [
                'name' => 'Espresso',
                'category_id' => $kategori->id,
                'price' => 18000,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Espresso')
            ->assertJsonPath('data.tenant_id', $tenantId);

        $this->assertDatabaseHas('products', [
            'name' => 'Espresso',
            'tenant_id' => $tenantId,
            'category_id' => $kategori->id,
        ]);
    }

    public function test_tidak_bisa_pakai_kategori_milik_tenant_lain_422(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $kategoriB = Category::create(['tenant_id' => $tenantB, 'name' => 'Punya B']);

        $this->withHeaders($this->authHeaders($tenantA))
            ->postJson('/api/products', [
                'name' => 'Curian',
                'category_id' => $kategoriB->id,
                'price' => 10000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_harga_wajib_dan_numerik_422(): void
    {
        $tenantId = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/products', ['name' => 'Tanpa Harga'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->postJson('/api/products', ['name' => 'X', 'price' => 1000])
            ->assertUnauthorized();
    }

    public function test_index_hanya_menampilkan_produk_milik_tenant(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        Product::create(['tenant_id' => $tenantA, 'name' => 'A1', 'price' => 1000]);
        Product::create(['tenant_id' => $tenantB, 'name' => 'B1', 'price' => 2000]);

        $this->withHeaders($this->authHeaders($tenantA))
            ->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A1');
    }

    public function test_tidak_bisa_menghapus_produk_tenant_lain_404(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $milikB = Product::create(['tenant_id' => $tenantB, 'name' => 'B1', 'price' => 2000]);

        $this->withHeaders($this->authHeaders($tenantA))
            ->deleteJson("/api/products/{$milikB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $milikB->id]);
    }
}
