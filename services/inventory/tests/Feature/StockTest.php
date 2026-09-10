<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class StockTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    /**
     * Catalog palsu untuk GET /api/stock, yang kini menanyakan nama bahan.
     *
     * Dipanggil EKSPLISIT di tiap test yang menyentuh endpoint itu, bukan
     * sekali di setUp(). Versi setUp sempat ditulis dan menipu: Laravel memakai
     * stub PERTAMA yang cocok, jadi fake di setUp menang atas fake yang
     * dipasang di dalam test — dua test hijau sambil membaca balasan kosong
     * yang bukan balasan yang mereka maksud. Hijau karena alasan yang salah
     * lebih buruk daripada merah.
     *
     * Tanpa fake sama sekali, test akan memanggil jaringan sungguhan: bukan
     * merah, melainkan tiga detik timeout lalu hijau dengan nama kosong.
     *
     * @param  array<int, array{id: string, name: string, unit: string}>  $bahan
     */
    private function palsukanCatalog(array $bahan = []): void
    {
        Http::fake(['*/api/ingredient*' => Http::response($bahan, 200)]);
    }

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

    /**
     * Pagar kebocoran: daftar kunci balasan dikunci, bukan sekadar dicek ada.
     *
     * Endpoint ini terbuka untuk kasir. Tanpa penguncian ini, kolom apa pun
     * yang ditambahkan ke `stock_balances` nanti — harga beli, pemasok — ikut
     * terkirim ke layar kasir hanya karena ia ada di tabel, dan tak ada satu
     * tes pun yang berubah merah untuk memberitahu kita.
     */
    public function test_daftar_stok_hanya_mengirim_medan_yang_diizinkan(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $headers = $this->authHeaders($tenant, $outlet);

        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);
        $this->palsukanCatalog([['id' => $bahan, 'name' => 'Susu', 'unit' => 'ml']]);

        $res = $this->withHeaders($headers)->getJson('/api/stock');

        $res->assertOk();
        $baris = $res->json('data.0');
        $this->assertSame(
            ['ingredient_id', 'ingredient_name', 'qty_on_hand', 'min_stock', 'updated_at'],
            array_keys($baris),
        );
        $this->assertSame($bahan, $baris['ingredient_id']);
    }

    /**
     * Nama bahan datang dari Catalog, bukan dari database ini.
     *
     * Tanpa ini kasir melihat deretan UUID dan angka — benar secara data, tak
     * berguna secara praktik. Nama tinggal di Catalog karena tabel bahannya
     * owner-only; Inventory yang mengambilnya lewat token service supaya kasir
     * tak pernah perlu izin ke Catalog sama sekali.
     */
    public function test_daftar_stok_menyertakan_nama_bahan_dari_catalog(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $headers = $this->authHeaders($tenant, $outlet);

        Http::fake(['*/api/ingredient*' => Http::response([
            ['id' => $bahan, 'name' => 'Susu Full Cream', 'unit' => 'ml'],
        ], 200)]);

        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        $this->withHeaders($headers)->getJson('/api/stock')
            ->assertOk()
            ->assertJsonPath('data.0.ingredient_id', $bahan)
            ->assertJsonPath('data.0.ingredient_name', 'Susu Full Cream');
    }

    /**
     * Catalog mati tak boleh mematikan layar stok.
     *
     * Angka saldonya ada di database service INI dan tetap benar; yang hilang
     * cuma namanya. Menolak seluruh permintaan berarti kasir kehilangan
     * informasi yang sebenarnya utuh di tangan kita — dan di tengah jam sibuk,
     * layar stok yang kosong lebih berbahaya daripada layar berisi UUID.
     */
    public function test_catalog_mati_tetap_mengirim_saldo_dengan_nama_null(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $headers = $this->authHeaders($tenant, $outlet);

        $this->withHeaders($headers)->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        Http::fake(['*/api/ingredient*' => Http::response(null, 503)]);

        $this->withHeaders($headers)->getJson('/api/stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ingredient_name', null)
            // Angkanya harus tetap benar — inilah alasan permintaannya tak digagalkan.
            ->assertJsonPath('data.0.qty_on_hand', '5000.000');
    }

    /**
     * Bahan yang dibuat di Catalog tapi BELUM pernah distok tetap tampil di layar
     * stok dengan saldo 0 — inilah jalan bagi owner untuk restock pertama kali.
     * Sebelumnya bahan baru tak pernah muncul (tak punya baris saldo), dan karena
     * tombol "Barang masuk" menempel pada baris yang ada, saldonya tak bisa diisi
     * sama sekali (buntu).
     */
    public function test_bahan_belum_distok_tampil_dengan_saldo_nol(): void
    {
        [$tenant, $outlet, $bahan] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->palsukanCatalog([['id' => $bahan, 'name' => 'Susu Full Cream', 'unit' => 'ml']]);

        $this->withHeaders($this->authHeaders($tenant, $outlet))
            ->getJson('/api/stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ingredient_id', $bahan)
            ->assertJsonPath('data.0.ingredient_name', 'Susu Full Cream')
            ->assertJsonPath('data.0.qty_on_hand', 0)
            ->assertJsonPath('data.0.min_stock', 0)
            ->assertJsonPath('data.0.updated_at', null);
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

    /** index di-scope outlet: outlet lain (tenant sama) melihat saldonya SENDIRI (0), bukan saldo outlet ini. */
    public function test_saldo_di_scope_per_outlet(): void
    {
        $tenant = (string) Str::uuid();
        [$outletA, $outletB] = [(string) Str::uuid(), (string) Str::uuid()];
        $bahan = (string) Str::uuid();

        $this->withHeaders($this->authHeaders($tenant, $outletA))
            ->postJson('/api/stock/restock', ['ingredient_id' => $bahan, 'qty' => 5000]);

        // Owner outlet B (tenant sama) melihat bahan yang sama (dari Catalog),
        // tapi saldonya 0 — bukan 5000 milik outlet A.
        $this->palsukanCatalog([['id' => $bahan, 'name' => 'Susu', 'unit' => 'ml']]);
        $this->withHeaders($this->authHeaders($tenant, $outletB))
            ->getJson('/api/stock')
            ->assertOk()
            ->assertJsonPath('data.0.ingredient_id', $bahan)
            ->assertJsonPath('data.0.qty_on_hand', 0);
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

        $this->palsukanCatalog([['id' => $bahan, 'name' => 'Susu', 'unit' => 'ml']]);

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
