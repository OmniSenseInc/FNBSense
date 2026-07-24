<?php

namespace Tests\Feature;

use App\Messaging\ConsumeOutcome;
use App\Messaging\OrderPaidConsumer;
use App\Models\ProcessedEvent;
use App\Models\SalesFact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderPaidConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_membentuk_read_model_dan_idempoten(): void
    {
        $event = $this->event();
        $consumer = app(OrderPaidConsumer::class);

        $this->assertSame(ConsumeOutcome::Ack, $consumer->handle($event));
        $this->assertSame(ConsumeOutcome::Ack, $consumer->handle($event));
        $this->assertSame(1, SalesFact::count());
        $this->assertSame(1, ProcessedEvent::count());
        $this->assertDatabaseHas('product_sales_facts', [
            'product_name' => 'Espresso', 'qty' => 2, 'line_total' => 20000,
        ]);
        $this->assertDatabaseHas('sales_facts', [
            'gross_subtotal' => 20000,
            'discount_total' => 5000,
            'subtotal' => 15000,
            'promotion_id' => $event['payload']['promotion']['id'],
            'promotion_name' => 'Diskon Launching',
            'promotion_template' => 'order_fixed',
        ]);
    }

    public function test_event_lama_tanpa_product_name_tetap_diterima(): void
    {
        $event = $this->event();
        unset($event['payload']['items'][0]['product_name']);
        unset(
            $event['payload']['totals']['gross_subtotal'],
            $event['payload']['totals']['discount_total'],
            $event['payload']['promotion'],
        );

        $this->assertSame(ConsumeOutcome::Ack, app(OrderPaidConsumer::class)->handle($event));
        $this->assertDatabaseHas('product_sales_facts', ['product_name' => null]);
    }

    public function test_amplop_rusak_masuk_dead_letter(): void
    {
        $event = $this->event();
        $event['payload']['totals']['grand_total'] = 1;

        $this->assertSame(ConsumeOutcome::Dead, app(OrderPaidConsumer::class)->handle($event));
        $this->assertSame(0, SalesFact::count());
    }

    public function test_id_non_uuid_ditolak_bukan_requeue_tanpa_akhir(): void
    {
        $event = $this->event();
        $event['event_id'] = str_repeat('x', 100);

        $this->assertSame(ConsumeOutcome::Dead, app(OrderPaidConsumer::class)->handle($event));
        $this->assertSame(0, SalesFact::count());
    }

    public function test_relasi_subtotal_kotor_dan_diskon_yang_rusak_ditolak(): void
    {
        $event = $this->event();
        $event['payload']['totals']['gross_subtotal'] = 19000;

        $this->assertSame(ConsumeOutcome::Dead, app(OrderPaidConsumer::class)->handle($event));
        $this->assertSame(0, SalesFact::count());
    }

    private function event(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.paid',
            'occurred_at' => '2026-07-22T10:00:00+07:00',
            'tenant_id' => (string) Str::uuid(),
            'outlet_id' => (string) Str::uuid(),
            'payload' => [
                'order_id' => (string) Str::uuid(),
                'payment_method' => 'cash',
                'totals' => [
                    'gross_subtotal' => 20000,
                    'discount_total' => 5000,
                    'subtotal' => 15000,
                    'service_charge' => 750,
                    'tax' => 1575,
                    'grand_total' => 17325,
                ],
                'promotion' => [
                    'id' => (string) Str::uuid(),
                    'name' => 'Diskon Launching',
                    'template' => 'order_fixed',
                ],
                'items' => [[
                    'product_id' => (string) Str::uuid(), 'product_name' => 'Espresso',
                    'qty' => 2, 'unit_price' => 10000,
                ]],
            ],
        ];
    }
}
