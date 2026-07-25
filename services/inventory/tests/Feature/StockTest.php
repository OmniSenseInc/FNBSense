<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class StockTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    /** Restock nambah saldo & mencatat tepat 1 movement bertanda benar. */
    public function test_owner_restock_menambah_saldo_dan_mencatat_movement(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];
        $bahan = (string) Str::uuid();

        $res = $this->withHeaders($this->authHeaders($tenant, $outlet))
            ->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        $res->assertCreated();
        $this->assertDatabaseHas('stock_balances', [
            'tenant_id' => $tenant,
            'outlet_id' => $outlet,
            'ingredient_id' => $bahan,
            'qty_on_hand' => 5000,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'outlet_id' => $outlet,
            'ingredient_id' => $bahan,
            'qty_delta' => 5000,
            'reason' => 'restock',
            'order_id' => null,
        ]);
    }

    /** Restock kedua akumulatif; saldo == jumlah seluruh movement (invarian ledger). */
    public function test_restock_kedua_akumulatif_dan_saldo_sama_dengan_jumlah_ledger(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $headers = $this->authHeaders($tenant, $outlet);

        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);
        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 2000]);

        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => 7000]);
        $this->assertDatabaseCount('stock_movements', 2);

        // Saldo harus persis rekonstruksi dari ledger — bukan angka bebas.
        $saldo = (float) \App\Models\StockBalance::where('ingredient_id', $bahan)->value('qty_on_hand');
        $jumlahLedger = (float) \App\Models\StockMovement::where('ingredient_id', $bahan)->sum('qty_delta');
        $this->assertSame($saldo, $jumlahLedger);
    }

    /** Opname: input hasil hitung fisik lebih kecil → catat selisih negatif. */
    public function test_adjust_opname_menyesuaikan_saldo_dan_mencatat_selisih(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $headers = $this->authHeaders($tenant, $outlet);

        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);
        $res = $this->withHeaders($headers)->postJson('/api/stock/adjust', ['ingredient_id' => $bahan, 'counted_qty' => 4800]);

        $res->assertOk();
        $this->assertDatabaseHas('stock_balances', ['ingredient_id' => $bahan, 'qty_on_hand' => 4800]);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $bahan,
            'qty_delta' => -200,
            'reason' => 'manual_adjust',
        ]);
    }

    /** Opname tanpa selisih (fisik == sistem) tak boleh mengotori ledger. */
    public function test_adjust_tanpa_selisih_tak_membuat_movement(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $headers = $this->authHeaders($tenant, $outlet);

        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);
        $this->withHeaders($headers)->postJson('/api/stock/adjust', ['ingredient_id' => $bahan, 'counted_qty' => 5000]);

        // Hanya movement restock yang ada; adjust nol-selisih tak menambah baris.
        $this->assertDatabaseCount('stock_movements', 1);
    }

    /** index di-scope outlet: outlet lain (tenant sama) tak melihat saldo ini. */
    public function test_saldo_di_scope_per_outlet(): void
    {
        $tenant = (string) Str::uuid();
        [$outletA, $outletB] = [(string) Str::uuid(), (string) Str::uuid()];
        $bahan = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenant, $outletA))
            ->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        // Owner outlet B (tenant sama) lihat daftarnya sendiri — kosong.
        $this->withHeaders($this->authHeaders($tenant, $outletB))
            ->getJson('/api/stock')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Anti-IDOR: adjust dari outlet A atas bahan yang hanya distok outlet B
     *  tak menyentuh saldo B; malah bikin baris baru milik A. */
    public function test_adjust_tak_menyentuh_saldo_outlet_lain(): void
    {
        $tenant = (string) Str::uuid();
        [$outletA, $outletB] = [(string) Str::uuid(), (string) Str::uuid()];
        $bahan = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenant, $outletB))
            ->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        $this->withHeaders($this->authHeaders($tenant, $outletA))
            ->postJson('/api/stock/adjust', ['ingredient_id' => $bahan, 'counted_qty' => 100]);

        // Saldo B utuh; A punya baris terpisah.
        $this->assertDatabaseHas('stock_balances', ['outlet_id' => $outletB, 'ingredient_id' => $bahan, 'qty_on_hand' => 5000]);
        $this->assertDatabaseHas('stock_balances', ['outlet_id' => $outletA, 'ingredient_id' => $bahan, 'qty_on_hand' => 100]);
    }

    /** qty <= 0 ditolak di boundary (saldo tak boleh berkurang lewat "restock"). */
    public function test_restock_qty_nol_ditolak_422(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];

        $this->withHeaders($this->authHeaders($tenant, $outlet))
            ->postJson('/api/stock/restock', ['ingredient_id' => (string) Str::uuid(), 'qty' => 0])
            ->assertStatus(422);
    }

    /** Role non-owner (kasir) ditolak. */
    public function test_kasir_ditolak_403(): void
    {
        [$tenant, $outlet] = [(string) Str::uuid(), (string) Str::uuid()];

        $this->withHeaders($this->authHeaders($tenant, $outlet, 'cashier'))
            ->postJson('/api/stock/restock', ['ingredient_id' => (string) Str::uuid(), 'qty' => 5000])
            ->assertStatus(403);
    }

    /** Tanpa token → 401. */
    public function test_tanpa_token_ditolak_401(): void
    {
        $this->postJson('/api/stock/restock', ['ingredient_id' => (string) Str::uuid(), 'qty' => 5000])
            ->assertStatus(401);
    }

    /** Token valid TAPI tanpa klaim outlet_id → ditolak (fail-fast, cegah null merembes). */
    public function test_token_tanpa_outlet_id_ditolak_401(): void
    {
        // Token owner sah dari sisi tanda tangan, tapi sengaja tak bawa outlet_id.
        $payload = JWTAuth::factory()->customClaims([
            'sub' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'role' => 'owner',
        ])->make();
        $token = JWTAuth::encode($payload)->get();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/stock/restock', ['ingredient_id' => (string) Str::uuid(), 'qty' => 5000])
            ->assertStatus(401);
    }

    /** F8a-5 / RBAC.md: kasir BOLEH lihat saldo stok (baca), tapi tetap tak boleh mengubah. */
    public function test_kasir_boleh_lihat_stok_tetapi_tak_bisa_mengubah(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        // Owner mengisi stok dulu.
        $this->withHeaders($this->authHeaders($tenant, $outlet))
            ->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        // Kasir BOLEH lihat saldo (200) — perubahan RBAC F8a-5.
        $this->withHeaders($this->authHeaders($tenant, $outlet, 'cashier'))
            ->getJson('/api/stock')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Tapi kasir tetap TAK boleh mengubah stok (opname owner-only).
        $this->withHeaders($this->authHeaders($tenant, $outlet, 'cashier'))
            ->postJson('/api/stock/adjust', ['ingredient_id' => $bahan, 'counted_qty' => 100])
            ->assertStatus(403);
    }
}
