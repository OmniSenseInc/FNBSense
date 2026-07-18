<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Endpoint kasir (langkah 8): antrean, confirm-payment idempoten, cancel.
 *
 * Fokus test: jalur uang tak boleh dobel (idempotensi), transisi status hanya
 * sah dari PENDING, dan isolasi outlet (404 untuk milik orang lain).
 */
class CashierOrderTest extends TestCase
{
    use MintsToken, RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    private string $cashierId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->cashierId = (string) Str::uuid();
    }

    /** @return array<string, string> */
    private function cashierHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->mintToken(
            $this->tenantId, $this->outletId, 'cashier', $this->cashierId,
        )];
    }

    private function makeOrder(
        OrderStatus $status = OrderStatus::Pending,
        ?string $tenantId = null,
        ?string $outletId = null,
    ): Order {
        $order = new Order([
            'order_type' => OrderType::DineIn->value,
            'customer_name' => 'Budi',
        ]);
        $order->tenant_id = $tenantId ?? $this->tenantId;
        $order->outlet_id = $outletId ?? $this->outletId;
        $order->order_number = strtoupper(Str::random(6));
        $order->subtotal = 20000;
        $order->service_charge = 1000;
        $order->tax = 2310;
        $order->grand_total = 23310;   // = subtotal + service_charge + tax (CHECK DB)
        $order->tax_percent = 11;
        $order->service_charge_percent = 5;
        $order->expires_at = now()->addMinutes(30);
        $order->status = $status;      // status tak fillable -> di-set langsung
        $order->save();

        $order->items()->create([
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Espresso',
            'unit_price' => 10000,
            'qty' => 2,
            'line_total' => 20000,     // = unit_price * qty (CHECK DB)
        ]);

        return $order;
    }

    // ---- Antrean --------------------------------------------------------

    /** Antrean hanya berisi order outlet ini; milik outlet/tenant lain tak muncul. */
    public function test_antrean_hanya_menampilkan_order_outlet_sendiri(): void
    {
        $this->makeOrder();
        $this->makeOrder();
        // Order tenant lain — TIDAK boleh bocor ke antrean kasir ini.
        $this->makeOrder(OrderStatus::Pending, (string) Str::uuid(), (string) Str::uuid());
        // Order tenant sama tapi outlet lain — juga tak boleh muncul.
        $this->makeOrder(OrderStatus::Pending, $this->tenantId, (string) Str::uuid());

        $this->withHeaders($this->cashierHeaders())
            ->getJson('/api/cashier/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** Filter status bergigi: paid tak muncul saat minta pending. */
    public function test_antrean_bisa_difilter_status(): void
    {
        $this->makeOrder(OrderStatus::Pending);
        $this->makeOrder(OrderStatus::Paid);

        $this->withHeaders($this->cashierHeaders())
            ->getJson('/api/cashier/orders?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    // ---- Confirm payment ------------------------------------------------

    /** Jalur bahagia: PENDING -> PAID, satu baris outbox dengan amplop kontrak. */
    public function test_confirm_payment_membuat_paid_dan_satu_outbox(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", [
                'payment_method' => 'qris_static',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_method', 'qris_static')
            ->assertJsonPath('data.confirmed_by', $this->cashierId);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'payment_method' => 'qris_static',
            'confirmed_by' => $this->cashierId,
        ]);

        $this->assertSame(1, Outbox::count());

        $envelope = Outbox::first()->payload;
        $this->assertSame('order.paid', $envelope['event_type']);
        $this->assertSame($this->tenantId, $envelope['tenant_id']);
        $this->assertSame($this->outletId, $envelope['outlet_id']);
        $this->assertSame($order->id, $envelope['payload']['order_id']);
        $this->assertSame($this->cashierId, $envelope['payload']['confirmed_by']);
        // Uang WAJIB integer rupiah (kontrak). is_int, bukan sekadar sama nilainya.
        $this->assertSame(23310, $envelope['payload']['totals']['grand_total']);
        $this->assertIsInt($envelope['payload']['totals']['subtotal']);
        $this->assertIsInt($envelope['payload']['items'][0]['unit_price']);
    }

    /**
     * INTI langkah 8: idempotensi. Konfirmasi dua kali -> tetap satu PAID, tetap
     * SATU baris outbox. Ini yang mencegah double-charge & double-potong-stok.
     */
    public function test_confirm_payment_idempoten(): void
    {
        $order = $this->makeOrder();

        $first = $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'qris_static'])
            ->assertOk();

        $second = $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'cash'])
            ->assertOk();

        // Konfirmasi kedua tak boleh menulis outbox baru maupun mengubah state.
        $this->assertSame(1, Outbox::count(), 'confirm kedua tak boleh menulis outbox lagi');
        $second->assertJsonPath('data.status', 'paid')
            // payment_method pertama (qris_static) yang menang; 'cash' diabaikan.
            ->assertJsonPath('data.payment_method', 'qris_static');
        $this->assertSame(1, Order::where('status', 'paid')->count());
    }

    /** Order CANCELLED tak bisa dibayar -> 409, tanpa outbox. */
    public function test_confirm_payment_order_cancelled_ditolak_409(): void
    {
        $order = $this->makeOrder(OrderStatus::Cancelled);

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'cash'])
            ->assertStatus(409);

        $this->assertSame(0, Outbox::count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    /** Order EXPIRED tak bisa dibayar -> 409. */
    public function test_confirm_payment_order_expired_ditolak_409(): void
    {
        $order = $this->makeOrder(OrderStatus::Expired);

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'cash'])
            ->assertStatus(409);

        $this->assertSame(0, Outbox::count());
    }

    /** Order milik outlet lain -> 404 (bukan 403), tak berubah, tak ada outbox. */
    public function test_confirm_payment_order_outlet_lain_404(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending, $this->tenantId, (string) Str::uuid());

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'cash'])
            ->assertNotFound();

        $this->assertSame(0, Outbox::count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    /** payment_method wajib -> 422, order tak berubah. */
    public function test_confirm_payment_tanpa_metode_ditolak_422(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Outbox::count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    /** Metode bayar di luar enum -> 422 (tak boleh nilai sembarang). */
    public function test_confirm_payment_metode_tak_dikenal_ditolak_422(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'gopay'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    /** Owner juga boleh konfirmasi (role:cashier,owner). */
    public function test_owner_juga_boleh_konfirmasi(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId, 'owner'))
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    // ---- Cancel ---------------------------------------------------------

    /** PENDING -> CANCELLED, alasan tersimpan, tanpa outbox (bukan event uang). */
    public function test_cancel_order_pending(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/cancel", ['reason' => 'Pelanggan batal'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'note' => 'Pelanggan batal',
        ]);
        $this->assertSame(0, Outbox::count());
    }

    /** PAID itu terminal: cancel order PAID -> 409, tetap paid. */
    public function test_cancel_order_paid_ditolak_409(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid);

        $this->withHeaders($this->cashierHeaders())
            ->postJson("/api/cashier/orders/{$order->id}/cancel", ['reason' => 'coba batalkan'])
            ->assertStatus(409);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    // ---- Auth -----------------------------------------------------------

    /** Role selain cashier/owner ditolak 403. */
    public function test_role_lain_ditolak_403(): void
    {
        $order = $this->makeOrder();

        $this->withHeaders($this->authHeaders($this->tenantId, $this->outletId, 'waiter'))
            ->postJson("/api/cashier/orders/{$order->id}/confirm-payment", ['payment_method' => 'cash'])
            ->assertForbidden();
    }

    /** Akun tanpa outlet (owner IAM saat ini) ditolak 403, bukan data kosong. */
    public function test_akun_tanpa_outlet_ditolak_403(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, null, 'cashier'))
            ->getJson('/api/cashier/orders')
            ->assertForbidden();
    }

    /** Tanpa token -> 401. */
    public function test_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/cashier/orders')->assertUnauthorized();
    }
}
