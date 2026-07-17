<?php

namespace Tests\Feature;

use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class TableTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
    }

    /** Jalur bahagia: owner bikin meja, dapat qr_token untuk dicetak. */
    public function test_owner_bisa_membuat_meja(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->postJson('/api/tables', ['label' => 'Meja 1']);

        $response->assertCreated()
            ->assertJsonPath('data.label', 'Meja 1')
            ->assertJsonPath('data.tenant_id', $this->tenantId)
            ->assertJsonPath('data.outlet_id', $this->outletId)
            ->assertJsonPath('data.is_active', true);

        // Owner wajib menerima qr_token — tanpa ini QR-nya tak bisa dicetak.
        $this->assertSame(32, strlen($response->json('data.qr_token')));
    }

    /**
     * Inti isolasi multi-tenant: tenant/outlet TIDAK BOLEH datang dari body.
     * Ini yang mencegah owner menanam meja di outlet milik orang lain.
     */
    public function test_tenant_dan_outlet_dari_body_diabaikan(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->postJson('/api/tables', [
                'label' => 'Meja 1',
                'tenant_id' => (string) Str::uuid(),
                'outlet_id' => (string) Str::uuid(),
                'qr_token' => 'TOKEN-PILIHAN-KLIEN',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->tenantId)
            ->assertJsonPath('data.outlet_id', $this->outletId);

        $this->assertNotSame('TOKEN-PILIHAN-KLIEN', $response->json('data.qr_token'));
    }

    /** Meja outlet lain harus 404 (bukan 403 — jangan bocorkan keberadaannya). */
    public function test_meja_outlet_lain_tidak_terlihat(): void
    {
        $milikOrangLain = Table::createForOutlet(
            (string) Str::uuid(),
            (string) Str::uuid(),
            ['label' => 'Meja Rahasia'],
        );

        $headers = $this->authHeaders($this->tenantId, $this->outletId);

        $this->withHeaders($headers)->getJson('/api/tables')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)
            ->putJson("/api/tables/{$milikOrangLain->id}", ['label' => 'Dibajak'])
            ->assertNotFound();

        $this->withHeaders($headers)
            ->deleteJson("/api/tables/{$milikOrangLain->id}")
            ->assertNotFound();

        $this->withHeaders($headers)
            ->postJson("/api/tables/{$milikOrangLain->id}/rotate-qr")
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $milikOrangLain->id, 'label' => 'Meja Rahasia']);
    }

    /**
     * Isolasi PER OUTLET, tenant-nya sama.
     *
     * Test di atas memakai tenant DAN outlet yang beda, jadi ia tetap hijau
     * walau filter outlet_id hilang dari scoped() — yang lolos gara-gara filter
     * tenant_id saja. Test ini sengaja menyamakan tenant supaya satu-satunya
     * yang memisahkan adalah outlet_id: begitu filter itu hilang, test ini merah.
     */
    public function test_meja_outlet_lain_dalam_tenant_sama_tidak_terlihat(): void
    {
        $cabangLain = Table::createForOutlet(
            $this->tenantId,
            (string) Str::uuid(),
            ['label' => 'Meja Cabang Lain'],
        );

        $headers = $this->authHeaders($this->tenantId, $this->outletId);

        $this->withHeaders($headers)->getJson('/api/tables')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($headers)
            ->putJson("/api/tables/{$cabangLain->id}", ['label' => 'Dibajak'])
            ->assertNotFound();

        $this->withHeaders($headers)
            ->deleteJson("/api/tables/{$cabangLain->id}")
            ->assertNotFound();

        $this->withHeaders($headers)
            ->postJson("/api/tables/{$cabangLain->id}/rotate-qr")
            ->assertNotFound();

        $this->assertDatabaseHas('tables', ['id' => $cabangLain->id, 'label' => 'Meja Cabang Lain']);
    }

    /** Label ganda dalam satu outlet ditolak rapi (422), bukan 500 dari DB. */
    public function test_label_ganda_di_outlet_sama_ditolak(): void
    {
        $headers = $this->authHeaders($this->tenantId, $this->outletId);

        $this->withHeaders($headers)->postJson('/api/tables', ['label' => 'Meja 1'])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/tables', ['label' => 'Meja 1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('label');
    }

    /** "Meja 1" boleh ada di tiap outlet — uniknya per outlet, bukan global. */
    public function test_label_sama_boleh_di_outlet_berbeda(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->postJson('/api/tables', ['label' => 'Meja 1'])
            ->assertCreated();

        $this->withHeaders($this->authHeaders($this->tenantId, (string) Str::uuid()))
            ->postJson('/api/tables', ['label' => 'Meja 1'])
            ->assertCreated();
    }

    /** Rotasi QR: token baru, tapi identitas meja tetap. */
    public function test_rotate_qr_mengganti_token_tanpa_mengganti_meja(): void
    {
        $table = Table::createForOutlet($this->tenantId, $this->outletId, ['label' => 'Meja 1']);
        $tokenLama = $table->qr_token;

        $response = $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId))
            ->postJson("/api/tables/{$table->id}/rotate-qr")
            ->assertOk();

        $this->assertSame($table->id, $response->json('data.id'), 'id meja tidak boleh berubah');
        $this->assertNotSame($tokenLama, $response->json('data.qr_token'), 'token lama harus mati');
        $this->assertSame(32, strlen($response->json('data.qr_token')));
    }

    /** Owner IAM saat ini outlet_id-nya null — harus ditolak jelas, bukan data kosong. */
    public function test_akun_tanpa_outlet_ditolak(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, null))
            ->getJson('/api/tables')
            ->assertForbidden();

        $this->withHeaders($this->authHeaders($this->tenantId, null))
            ->postJson('/api/tables', ['label' => 'Meja 1'])
            ->assertForbidden();
    }

    /** Kasir bukan owner: tak boleh mengelola meja. */
    public function test_kasir_tidak_boleh_mengelola_meja(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId, 'cashier'))
            ->postJson('/api/tables', ['label' => 'Meja 1'])
            ->assertForbidden();
    }

    /** Tanpa token sama sekali -> 401, bukan 500. */
    public function test_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/tables')->assertUnauthorized();
    }
}
