<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

/**
 * Penanda "siap diantar".
 *
 * Dua hal yang dijaga. Yang pertama urutan uang: barang tak boleh dinyatakan
 * keluar sebelum dibayar, dan urutan itu tak bisa diperbaiki belakangan. Yang
 * kedua kejujuran jam: penandaan berulang tak boleh menggeser "siap sejak
 * 14:41", sebab pelanggan yang sudah menunggu sepuluh menit akan melihat
 * pesanannya seolah baru saja selesai.
 */
class ReadyOrderTest extends TestCase
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

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->mintToken(
            $this->tenantId, $this->outletId, 'cashier', (string) Str::uuid(),
        )];
    }

    private function buatOrder(OrderStatus $status, ?string $outletId = null): Order
    {
        $order = new Order([
            'order_type' => 'dine_in',
            'customer_name' => 'Vincent',
        ]);

        $order->tenant_id = $this->tenantId;
        $order->outlet_id = $outletId ?? $this->outletId;
        $order->order_number = Str::upper(Str::random(6));
        $order->status = $status;
        $order->gross_subtotal = 20000;
        $order->discount_total = 0;
        $order->subtotal = 20000;
        $order->service_charge = 0;
        $order->tax = 0;
        $order->grand_total = 20000;
        $order->tax_percent = 0;
        $order->service_charge_percent = 0;
        // NOT NULL tanpa default — pesanan sungguhan selalu lahir dengan tenggat
        // dari OrderController, jadi di sini pun harus diisi.
        $order->expires_at = now()->addMinutes(30);
        $order->save();

        return $order;
    }

    public function test_pesanan_yang_sudah_dibayar_bisa_ditandai_siap(): void
    {
        $order = $this->buatOrder(OrderStatus::Paid);

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")
            ->assertOk()
            ->assertJsonPath('data.ready_at', fn ($nilai) => $nilai !== null);

        $this->assertNotNull($order->fresh()->ready_at);
    }

    /**
     * Cabut cabang "sudah ditandai" di markReady() dan test ini merah: jamnya
     * bergeser tiap ketukan, dan "siap sejak" berhenti berarti apa pun.
     */
    public function test_penandaan_kedua_tidak_menggeser_jam_siap(): void
    {
        $order = $this->buatOrder(OrderStatus::Paid);

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")->assertOk();
        $pertama = $order->fresh()->ready_at;

        $this->travel(3)->minutes();

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")->assertOk();

        $this->assertTrue($order->fresh()->ready_at->equalTo($pertama));
    }

    /**
     * Yang paling mahal: barang keluar sebelum uangnya diterima.
     */
    public function test_pesanan_belum_dibayar_ditolak(): void
    {
        $order = $this->buatOrder(OrderStatus::Pending);

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")
            ->assertStatus(409);

        $this->assertNull($order->fresh()->ready_at);
    }

    public function test_pesanan_dibatalkan_ditolak(): void
    {
        $order = $this->buatOrder(OrderStatus::Cancelled);

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")
            ->assertStatus(409);
    }

    /** Pesanan outlet lain tak pernah terlihat, apalagi bisa ditandai. */
    public function test_pesanan_outlet_lain_menjawab_404(): void
    {
        $order = $this->buatOrder(OrderStatus::Paid, (string) Str::uuid());

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")
            ->assertNotFound();
    }

    /** Pelanggan ikut melihatnya — di situlah garis kemajuan mengambil jamnya. */
    public function test_jam_siap_sampai_ke_layar_pelanggan(): void
    {
        $order = $this->buatOrder(OrderStatus::Paid);

        $this->withHeaders($this->headers())
            ->postJson("/api/cashier/orders/{$order->id}/ready")->assertOk();

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.ready_at', fn ($nilai) => $nilai !== null);
    }
}
