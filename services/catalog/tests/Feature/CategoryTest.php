<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    public function test_owner_bisa_membuat_kategori(): void
    {
        $tenantId = (string) Str::uuid();

        $response = $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/categories', ['name' => 'Kopi', 'sort_order' => 1]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Kopi')
            ->assertJsonPath('data.tenant_id', $tenantId);

        $this->assertDatabaseHas('categories', ['name' => 'Kopi', 'tenant_id' => $tenantId]);
    }

    public function test_tenant_id_diambil_dari_token_bukan_body(): void
    {
        $tenantId = (string) Str::uuid();
        $palsu = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/categories', ['name' => 'Teh', 'tenant_id' => $palsu])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $tenantId);

        $this->assertDatabaseMissing('categories', ['tenant_id' => $palsu]);
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->postJson('/api/categories', ['name' => 'Kopi'])
            ->assertUnauthorized();
    }

    public function test_role_cashier_ditolak_403(): void
    {
        $tenantId = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenantId, 'cashier'))
            ->postJson('/api/categories', ['name' => 'Kopi'])
            ->assertForbidden();
    }

    public function test_nama_wajib_diisi_422(): void
    {
        $tenantId = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/categories', ['sort_order' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_index_hanya_menampilkan_kategori_milik_tenant(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        Category::create(['tenant_id' => $tenantA, 'name' => 'Punya A']);
        Category::create(['tenant_id' => $tenantB, 'name' => 'Punya B']);

        $this->withHeaders($this->authHeaders($tenantA))
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Punya A');
    }

    public function test_tidak_bisa_mengubah_kategori_tenant_lain_404(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $milikB = Category::create(['tenant_id' => $tenantB, 'name' => 'Punya B']);

        $this->withHeaders($this->authHeaders($tenantA))
            ->putJson("/api/categories/{$milikB->id}", ['name' => 'Dibajak'])
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $milikB->id, 'name' => 'Punya B']);
    }

    public function test_owner_bisa_menghapus_kategori_sendiri(): void
    {
        $tenantId = (string) Str::uuid();
        $kategori = Category::create(['tenant_id' => $tenantId, 'name' => 'Kopi']);

        $this->withHeaders($this->authHeaders($tenantId))
            ->deleteJson("/api/categories/{$kategori->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $kategori->id]);
    }
}
