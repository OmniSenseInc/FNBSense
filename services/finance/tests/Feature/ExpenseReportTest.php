<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Pengeluaran + laporan lintas-shift/harian (F5d). Gigi: uang keluar > 0 (batas
 * boundary + CHECK DB), scope tenant+outlet dari JWT (bukan body), laporan
 * net = omzet − pengeluaran benar, window rentang tanggal half-open, isolasi outlet.
 */
class ExpenseReportTest extends TestCase
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
            'tenant_id' => $tenant, 'outlet_id' => $outlet,
            // F7: gross_subtotal NOT NULL tanpa default + CHECK subtotal =
            // gross_subtotal - discount_total. Tanpa promo, gross == subtotal.
            'gross_subtotal' => $grand, 'discount_total' => 0,
            'subtotal' => $grand, 'service_charge' => 0, 'tax' => 0, 'grand_total' => $grand,
            'payment_method' => $method, 'paid_at' => $paidAt,
        ]);
    }

    private function expense(string $tenant, string $outlet, int $amount, string $cat, string $spentAt): void
    {
        Expense::create([
            'tenant_id' => $tenant, 'outlet_id' => $outlet,
            'category' => $cat, 'amount' => $amount, 'spent_at' => $spentAt,
            'recorded_by' => (string) Str::uuid(),
        ]);
    }

    public function test_catat_pengeluaran(): void
    {
        [$tenant, $outlet] = $this->ids();

        $res = $this->postJson('/api/expenses', [
            'category' => 'bahan', 'amount' => 45000, 'note' => 'beli kopi',
        ], $this->authHeaders($tenant, $outlet, 'cashier'));

        $res->assertCreated()->assertJsonPath('data.category', 'bahan')->assertJsonPath('data.amount', 45000);
        $this->assertDatabaseHas('expenses', ['outlet_id' => $outlet, 'amount' => 45000, 'category' => 'bahan']);
    }

    /** Batas uang: nol/negatif ditolak di validasi (bukan lolos jadi baris racun). */
    public function test_amount_nol_atau_negatif_ditolak(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'cashier');

        $this->postJson('/api/expenses', ['category' => 'bahan', 'amount' => 0], $h)->assertStatus(422);
        $this->postJson('/api/expenses', ['category' => 'bahan', 'amount' => -5000], $h)->assertStatus(422);
        $this->assertSame(0, Expense::count());
    }

    /** Kategori di luar set tertutup ditolak → breakdown laporan tak pecah. */
    public function test_kategori_asing_ditolak(): void
    {
        [$tenant, $outlet] = $this->ids();

        $this->postJson('/api/expenses', ['category' => 'ngasal', 'amount' => 1000], $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertStatus(422);
        $this->assertSame(0, Expense::count());
    }

    /** spent_at masa depan ditolak → tak bisa selipin pengeluaran hantu di luar window. */
    public function test_spent_at_masa_depan_ditolak(): void
    {
        [$tenant, $outlet] = $this->ids();

        $this->postJson('/api/expenses', [
            'category' => 'bahan', 'amount' => 1000, 'spent_at' => now()->addDay()->toDateTimeString(),
        ], $this->authHeaders($tenant, $outlet, 'cashier'))->assertStatus(422);
        $this->assertSame(0, Expense::count());
    }

    /** Laporan laba-rugi = owner only; akun kasir ditolak (403). */
    public function test_laporan_hanya_owner(): void
    {
        [$tenant, $outlet] = $this->ids();

        $this->getJson('/api/reports?from=2026-07-20&to=2026-07-20', $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertStatus(403);
    }

    /** outlet/pencatat dari JWT, bukan body — kirim outlet_id palsu tak berpengaruh. */
    public function test_scope_dari_jwt_bukan_body(): void
    {
        [$tenant, $outlet] = $this->ids();
        $palsu = (string) Str::uuid();

        $this->postJson('/api/expenses', [
            'category' => 'bahan', 'amount' => 1000,
            'outlet_id' => $palsu, 'tenant_id' => $palsu, 'recorded_by' => $palsu,
        ], $this->authHeaders($tenant, $outlet, 'cashier'))->assertCreated();

        $this->assertDatabaseHas('expenses', ['outlet_id' => $outlet]);
        $this->assertDatabaseMissing('expenses', ['outlet_id' => $palsu]);
    }

    /** index cuma pengeluaran outlet sendiri. */
    public function test_index_isolasi_outlet(): void
    {
        [$tenant, $outletA] = $this->ids();
        $outletB = (string) Str::uuid();
        $this->expense($tenant, $outletA, 1000, 'bahan', '2026-07-22 10:00:00');
        $this->expense($tenant, $outletB, 2000, 'bahan', '2026-07-22 10:00:00');

        $res = $this->getJson('/api/expenses', $this->authHeaders($tenant, $outletA, 'owner'));

        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 1000);
    }

    /** Laporan: net = omzet − pengeluaran, split cash/qris, rincian per kategori. */
    public function test_laporan_net_dan_rincian(): void
    {
        [$tenant, $outlet] = $this->ids();

        $this->sale($tenant, $outlet, 100000, 'cash', '2026-07-20 09:00:00');
        $this->sale($tenant, $outlet, 60000, 'qris_static', '2026-07-21 09:00:00');
        $this->expense($tenant, $outlet, 40000, 'bahan', '2026-07-20 08:00:00');
        $this->expense($tenant, $outlet, 10000, 'bahan', '2026-07-21 08:00:00');
        $this->expense($tenant, $outlet, 25000, 'operasional', '2026-07-21 12:00:00');

        $res = $this->getJson('/api/reports?from=2026-07-20&to=2026-07-21', $this->authHeaders($tenant, $outlet, 'owner'));

        $res->assertOk()
            ->assertJsonPath('data.sales.total', 160000)
            ->assertJsonPath('data.sales.cash', 100000)
            ->assertJsonPath('data.sales.qris', 60000)
            ->assertJsonPath('data.sales.transactions', 2)
            ->assertJsonPath('data.expenses.total', 75000)
            ->assertJsonPath('data.expenses.count', 3)
            ->assertJsonPath('data.expenses.by_category.bahan', 50000)
            ->assertJsonPath('data.expenses.by_category.operasional', 25000)
            ->assertJsonPath('data.net', 85000); // 160k - 75k
    }

    /** Window rentang half-open: sebelum from & sesudah to dibuang (batas hari). */
    public function test_laporan_window_rentang(): void
    {
        [$tenant, $outlet] = $this->ids();

        $this->sale($tenant, $outlet, 50000, 'cash', '2026-07-20 00:00:00'); // awal hari from → in
        $this->sale($tenant, $outlet, 70000, 'cash', '2026-07-20 23:59:59'); // akhir hari to → in
        $this->sale($tenant, $outlet, 99999, 'cash', '2026-07-19 23:59:59'); // sebelum from → out
        $this->sale($tenant, $outlet, 88888, 'cash', '2026-07-21 00:00:00'); // hari sesudah to → out
        $this->expense($tenant, $outlet, 30000, 'bahan', '2026-07-21 00:00:00'); // sesudah → out

        $this->getJson('/api/reports?from=2026-07-20&to=2026-07-20', $this->authHeaders($tenant, $outlet, 'owner'))
            ->assertOk()
            ->assertJsonPath('data.sales.total', 120000)
            ->assertJsonPath('data.sales.transactions', 2)
            ->assertJsonPath('data.expenses.total', 0);
    }

    /** Laporan outlet lain nol — tak bocor lintas-outlet. */
    public function test_laporan_isolasi_outlet(): void
    {
        [$tenant, $outletA] = $this->ids();
        $outletB = (string) Str::uuid();
        $this->sale($tenant, $outletB, 500000, 'cash', '2026-07-20 09:00:00');
        $this->expense($tenant, $outletB, 100000, 'bahan', '2026-07-20 09:00:00');

        $this->getJson('/api/reports?from=2026-07-20&to=2026-07-20', $this->authHeaders($tenant, $outletA, 'owner'))
            ->assertOk()
            ->assertJsonPath('data.sales.total', 0)
            ->assertJsonPath('data.net', 0);
    }

    public function test_laporan_butuh_from_to_valid(): void
    {
        [$tenant, $outlet] = $this->ids();
        $h = $this->authHeaders($tenant, $outlet, 'owner');

        $this->getJson('/api/reports', $h)->assertStatus(422);                          // wajib
        $this->getJson('/api/reports?from=2026-07-22&to=2026-07-20', $h)->assertStatus(422); // to < from
    }
}
