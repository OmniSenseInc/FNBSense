<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Langkah 9: command orders:expire.
 *
 * Test bergigi — expire hanya menyentuh PENDING yang lewat waktu. PAID
 * (terminal) & CANCELLED tak boleh tersenggol; PENDING yang belum lewat
 * tetap PENDING.
 */
class ExpireOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status, \DateTimeInterface $expiresAt): Order
    {
        $order = new Order([
            'order_type' => OrderType::DineIn->value,
            'customer_name' => 'Budi',
        ]);
        $order->tenant_id = (string) Str::uuid();
        $order->outlet_id = (string) Str::uuid();
        $order->order_number = strtoupper(Str::random(6));
        $order->gross_subtotal = 20000;
        $order->discount_total = 0;
        $order->subtotal = 20000;
        $order->service_charge = 1000;
        $order->tax = 2310;
        $order->grand_total = 23310;
        $order->tax_percent = 11;
        $order->service_charge_percent = 5;
        $order->expires_at = $expiresAt;
        $order->status = $status;
        $order->save();

        return $order;
    }

    public function test_pending_lewat_waktu_jadi_expired(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending, now()->subMinute());

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->fresh()->status);
    }

    public function test_pending_belum_lewat_tetap_pending(): void
    {
        $order = $this->makeOrder(OrderStatus::Pending, now()->addMinutes(30));

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_paid_lewat_waktu_tak_tersentuh(): void
    {
        $order = $this->makeOrder(OrderStatus::Paid, now()->subMinute());

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_cancelled_lewat_waktu_tak_tersentuh(): void
    {
        $order = $this->makeOrder(OrderStatus::Cancelled, now()->subMinute());

        $this->artisan('orders:expire')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }
}
