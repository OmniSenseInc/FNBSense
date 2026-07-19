<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class IngredientTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    public function test_owner_bisa_membuat_bahan(): void
    {
        $tenantId = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/ingredients', ['name' => 'Susu', 'unit' => 'ml'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Susu')
            ->assertJsonPath('data.tenant_id', $tenantId);

        $this->assertDatabaseHas('ingredients', ['name' => 'Susu', 'tenant_id' => $tenantId]);
    }

    public function test_unit_di_luar_enum_ditolak_422(): void
    {
        $tenantId = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/ingredients', ['name' => 'Aneh', 'unit' => 'liter'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit');
    }

    public function test_nama_duplikat_dalam_tenant_ditolak_422(): void
    {
        $tenantId = (string) Str::uuid();
        Ingredient::create(['tenant_id' => $tenantId, 'name' => 'Gula', 'unit' => 'g']);

        $this->withHeaders($this->authHeaders($tenantId))
            ->postJson('/api/ingredients', ['name' => 'Gula', 'unit' => 'g'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_nama_sama_beda_tenant_boleh(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        Ingredient::create(['tenant_id' => $tenantB, 'name' => 'Gula', 'unit' => 'g']);

        $this->withHeaders($this->authHeaders($tenantA))
            ->postJson('/api/ingredients', ['name' => 'Gula', 'unit' => 'g'])
            ->assertCreated();
    }

    public function test_index_hanya_menampilkan_bahan_milik_tenant(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        Ingredient::create(['tenant_id' => $tenantA, 'name' => 'A1', 'unit' => 'g']);
        Ingredient::create(['tenant_id' => $tenantB, 'name' => 'B1', 'unit' => 'g']);

        $this->withHeaders($this->authHeaders($tenantA))
            ->getJson('/api/ingredients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A1');
    }

    public function test_tidak_bisa_menghapus_bahan_tenant_lain_404(): void
    {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $milikB = Ingredient::create(['tenant_id' => $tenantB, 'name' => 'B1', 'unit' => 'g']);

        $this->withHeaders($this->authHeaders($tenantA))
            ->deleteJson("/api/ingredients/{$milikB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('ingredients', ['id' => $milikB->id]);
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->postJson('/api/ingredients', ['name' => 'X', 'unit' => 'g'])
            ->assertUnauthorized();
    }
}
