<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Shift kasir + laporan per-shift (F5c). Fokus gigi: guard 1-open-per-outlet di
 * DB, transisi open→closed terminal, laporan pisah cash/qris + selisih kas benar,
 * hanya penjualan di window yang kehitung, isolasi tenant/outlet.
 */
class ShiftTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    /** @return array{0:string,1:string} tenant, outlet */
    private function ids(): array
    {
        return [(string) Str::uuid(), (string) Str::uuid()];
    }

    private function sale(string $tenant, string $outlet, int $grand, string $method, string $paidAt): void
    {
        Sale::create([
            'order_id' => (string) Str::uuid(),
            'tenant_id' => $tenant,
            'outlet_id' => $outlet,
            // F7 menambah gross_subtotal (NOT NULL, tanpa default) + CHECK
            // subtotal = gross_subtotal - discount_total. Baris uji ini tanpa
            // promo, jadi gross == subtotal dan diskon nol.
            'gross_subtotal' => $grand,
            'discount_total' => 0,
            'subtotal' => $grand,
            'service_charge' => 0,
            'tax' => 0,
            'grand_total' => $grand,
            'payment_method' => $method,
            'paid_at' => $paidAt,
        ]);
    }

    public function test_buka_shift(): void
    {
        [$tenant, $outlet] = $this->ids();

        $res = $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $this->authHeaders($tenant, $outlet, 'cashier'));

        $res->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.opening_cash', 100000);
        $this->assertDatabaseHas('shifts', ['outlet_id' => $outlet, 'status' => 'open', 'open_key' => $outlet]);
    }

    /** Guard DB: outlet yang sudah punya shift open → open kedua ditolak 409. */
    public function test_dobel_buka_ditolak(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');

        $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $h)->assertCreated();
        $this->postJson('/api/shifts/open', ['opening_cash' => 50000], $h)->assertStatus(409);

        $this->assertSame(1, Shift::where('outlet_id', $outlet)->count());
    }

    /** Guard per-OUTLET, bukan global: outlet lain tetap boleh buka walau outlet ini open. */
    public function test_outlet_lain_tetap_boleh_buka(): void
    {
        [$tenant, $outletA] = $this->ids();
        $outletB = (string) Str::uuid();

        $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $this->authHeaders($tenant, $outletA, 'cashier'))->assertCreated();
        $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $this->authHeaders($tenant, $outletB, 'cashier'))->assertCreated();
    }

    public function test_tutup_shift(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');
        $id = $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $h)->json('data.id');

        $res = $this->postJson("/api/shifts/{$id}/close", ['closing_cash' => 150000], $h);

        $res->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closing_cash', 150000);
        // open_key dilepas → outlet boleh buka shift baru.
        $this->assertDatabaseHas('shifts', ['id' => $id, 'status' => 'closed', 'open_key' => null]);
    }

    public function test_tutup_dua_kali_ditolak(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');
        $id = $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $h)->json('data.id');

        $this->postJson("/api/shifts/{$id}/close", ['closing_cash' => 150000], $h)->assertOk();
        $this->postJson("/api/shifts/{$id}/close", ['closing_cash' => 999999], $h)->assertStatus(409);
    }

    /** Setelah tutup, outlet yang sama boleh buka shift baru (guard sudah dilepas). */
    public function test_setelah_tutup_bisa_buka_lagi(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');
        $id = $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $h)->json('data.id');
        $this->postJson("/api/shifts/{$id}/close", ['closing_cash' => 150000], $h)->assertOk();

        $this->postJson('/api/shifts/open', ['opening_cash' => 200000], $h)->assertCreated();
    }

    /**
     * Laporan: pisah cash/qris, expected = opening + cash (QRIS TAK masuk laci),
     * variance = counted - expected (boleh minus). Ini gigi uang F5c.
     */
    public function test_laporan_split_cash_qris_dan_variance(): void
    {
        [$tenant, $outlet] = $this->ids();

        $shift = Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 100000, 'opened_at' => '2026-07-22 08:00:00',
            'closed_by' => (string) Str::uuid(), 'closing_cash' => 250000, 'closed_at' => '2026-07-22 16:00:00',
            'status' => 'closed', 'open_key' => null,
        ]);

        // Di window: cash 50k + 30k, qris 40k.
        $this->sale($tenant, $outlet, 50000, 'cash', '2026-07-22 09:00:00');
        $this->sale($tenant, $outlet, 30000, 'cash', '2026-07-22 10:00:00');
        $this->sale($tenant, $outlet, 40000, 'qris_static', '2026-07-22 11:00:00');

        $res = $this->getJson("/api/shifts/{$shift->id}", $this->authHeaders($tenant, $outlet, 'owner'));

        $res->assertOk()
            ->assertJsonPath('data.report.cash_sales', 80000)
            ->assertJsonPath('data.report.qris_sales', 40000)
            ->assertJsonPath('data.report.total_sales', 120000)
            ->assertJsonPath('data.report.transactions', 3)
            ->assertJsonPath('data.report.expected_cash', 180000)   // 100k + 80k cash
            ->assertJsonPath('data.report.counted_cash', 250000)
            ->assertJsonPath('data.report.cash_variance', 70000);    // 250k - 180k
    }

    /** Hanya penjualan DALAM window shift yang kehitung (sebelum/sesudah dibuang). */
    public function test_laporan_hanya_window(): void
    {
        [$tenant, $outlet] = $this->ids();

        $shift = Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 0, 'opened_at' => '2026-07-22 08:00:00',
            'closed_by' => (string) Str::uuid(), 'closing_cash' => 50000, 'closed_at' => '2026-07-22 16:00:00',
            'status' => 'closed', 'open_key' => null,
        ]);

        $this->sale($tenant, $outlet, 50000, 'cash', '2026-07-22 12:00:00'); // in
        $this->sale($tenant, $outlet, 99999, 'cash', '2026-07-22 07:59:59'); // sebelum open
        $this->sale($tenant, $outlet, 88888, 'cash', '2026-07-22 16:00:00'); // == closed_at (half-open, keluar)

        $this->getJson("/api/shifts/{$shift->id}", $this->authHeaders($tenant, $outlet, 'owner'))
            ->assertOk()
            ->assertJsonPath('data.report.total_sales', 50000)
            ->assertJsonPath('data.report.transactions', 1);
    }

    /**
     * `GET /shifts/current` — satu-satunya cara menemukan shift terbuka tanpa
     * menyimpan id di sisi klien. Belum ada shift = keadaan normal, bukan error.
     */
    public function test_current_null_saat_belum_ada_shift(): void
    {
        [$tenant, $outlet] = $this->ids();

        $this->getJson('/api/shifts/current', $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** Shift terbuka ikut membawa X-report berjalan (belum ada counted/variance). */
    public function test_current_kembalikan_shift_terbuka_dengan_x_report(): void
    {
        [$tenant, $outlet] = $this->ids();

        $shift = Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 100000, 'opened_at' => now()->subHours(2),
            'status' => 'open', 'open_key' => $outlet,
        ]);
        $this->sale($tenant, $outlet, 50000, 'cash', now()->subHour()->toDateTimeString());

        $this->getJson('/api/shifts/current', $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertOk()
            ->assertJsonPath('data.id', $shift->id)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.report.cash_sales', 50000)
            ->assertJsonPath('data.report.expected_cash', 150000)
            ->assertJsonPath('data.report.counted_cash', null)
            ->assertJsonPath('data.report.cash_variance', null);
    }

    /** Shift yang sudah ditutup BUKAN current — kalau bocor, tombol tutup muncul lagi. */
    public function test_current_abaikan_shift_yang_sudah_ditutup(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');
        $id = $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $h)->json('data.id');
        $this->postJson("/api/shifts/{$id}/close", ['closing_cash' => 100000], $h)->assertOk();

        $this->getJson('/api/shifts/current', $h)->assertOk()->assertJsonPath('data', null);
    }

    /** Shift outlet lain tak boleh bocor lewat pintu yang tak menyebut id. */
    public function test_current_terisolasi_per_outlet(): void
    {
        [$tenant, $outlet] = $this->ids();
        $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $this->authHeaders($tenant, $outlet, 'cashier'))->assertCreated();

        $this->getJson('/api/shifts/current', $this->authHeaders($tenant, (string) Str::uuid(), 'cashier'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** Isolasi: shift milik outlet lain → 404 (bukan bocor/ubah). */
    public function test_isolasi_outlet(): void
    {
        [$tenant, $outlet] = $this->ids();
        $id = $this->postJson('/api/shifts/open', ['opening_cash' => 100000], $this->authHeaders($tenant, $outlet, 'cashier'))->json('data.id');

        $lain = $this->authHeaders($tenant, (string) Str::uuid(), 'owner'); // tenant sama, outlet beda
        $this->getJson("/api/shifts/{$id}", $lain)->assertNotFound();
        $this->postJson("/api/shifts/{$id}/close", ['closing_cash' => 1], $lain)->assertNotFound();
    }

    /** Riwayat (index): hanya shift tertutup, terbaru di atas, lengkap dengan laporan. */
    public function test_index_riwayat_hanya_tertutup_terbaru_di_atas(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');

        $lama = Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 100000, 'opened_at' => '2026-07-20 08:00:00',
            'closed_by' => (string) Str::uuid(), 'closing_cash' => 150000, 'closed_at' => '2026-07-20 16:00:00',
            'status' => 'closed', 'open_key' => null,
        ]);
        $baru = Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 200000, 'opened_at' => '2026-07-21 08:00:00',
            'closed_by' => (string) Str::uuid(), 'closing_cash' => 300000, 'closed_at' => '2026-07-21 16:00:00',
            'status' => 'closed', 'open_key' => null,
        ]);
        // Shift yang MASIH terbuka tak boleh bocor ke riwayat.
        Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 50000, 'opened_at' => '2026-07-22 08:00:00',
            'status' => 'open', 'open_key' => $outlet,
        ]);

        $this->getJson('/api/shifts', $h)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $baru->id)      // terbaru di atas
            ->assertJsonPath('data.1.id', $lama->id)
            ->assertJsonPath('data.0.report.cash_variance', 100000); // 300k - 200k
    }

    /** Riwayat outlet lain tak bocor: outlet B melihat daftar kosong. */
    public function test_index_riwayat_terisolasi_per_outlet(): void
    {
        [$tenant, $outletA] = $this->ids();
        Shift::create([
            'tenant_id' => $tenant, 'outlet_id' => $outletA, 'opened_by' => (string) Str::uuid(),
            'opening_cash' => 100000, 'opened_at' => '2026-07-20 08:00:00',
            'closed_by' => (string) Str::uuid(), 'closing_cash' => 150000, 'closed_at' => '2026-07-20 16:00:00',
            'status' => 'closed', 'open_key' => null,
        ]);

        $this->getJson('/api/shifts', $this->authHeaders($tenant, (string) Str::uuid(), 'owner'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
