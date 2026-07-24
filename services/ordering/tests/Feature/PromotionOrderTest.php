<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderSetting;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PromotionOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    private string $productId;

    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.catalog.promotions_enabled' => true]);
        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->productId = (string) Str::uuid();
        $this->table = Table::createForOutlet(
            $this->tenantId,
            $this->outletId,
            ['label' => 'Meja Promo'],
        );

        $setting = new OrderSetting([
            'tax_percent' => 10,
            'service_charge_percent' => 5,
            'order_expiry_minutes' => 30,
        ]);
        $setting->tenant_id = $this->tenantId;
        $setting->outlet_id = $this->outletId;
        $setting->save();
    }

    public function test_order_menerapkan_promo_server_side_dan_menyimpan_snapshot(): void
    {
        $promotionId = (string) Str::uuid();
        $this->fakeCatalog([
            'discount_total' => 5000,
            'promotion' => [
                'id' => $promotionId,
                'name' => 'Diskon Launching',
                'template' => 'order_fixed',
                'amount' => 5000,
                'priority' => 100,
            ],
        ]);

        $response = $this->postJson('/api/orders', $this->payload([
            'discount_total' => 19999,
            'grand_total' => 1,
        ]));

        // Gross 20.000 - diskon 5.000 = subtotal net 15.000.
        // Service 5%=750; pajak 10% dari 15.750=1.575; grand=17.325.
        $response->assertCreated()
            ->assertJsonPath('data.gross_subtotal', 20000)
            ->assertJsonPath('data.discount_total', 5000)
            ->assertJsonPath('data.subtotal', 15000)
            ->assertJsonPath('data.service_charge', 750)
            ->assertJsonPath('data.tax', 1575)
            ->assertJsonPath('data.grand_total', 17325)
            ->assertJsonPath('data.promotion.id', $promotionId);

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'gross_subtotal' => 20000,
            'discount_total' => 5000,
            'subtotal' => 15000,
            'grand_total' => 17325,
            'promotion_id' => $promotionId,
        ]);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains(
            $request->url(),
            '/api/internal/promotions/evaluate',
        )
            && $request->hasHeader('X-Service-Token', 'test-secret-catalog')
            && $request['tenant_id'] === $this->tenantId
            && $request['outlet_id'] === $this->outletId
            && $request['subtotal'] === 20000);
    }

    public function test_tanpa_promo_total_tetap_konsisten(): void
    {
        $this->fakeCatalog(['discount_total' => 0, 'promotion' => null]);

        $this->postJson('/api/orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.gross_subtotal', 20000)
            ->assertJsonPath('data.discount_total', 0)
            ->assertJsonPath('data.subtotal', 20000)
            ->assertJsonPath('data.grand_total', 23100)
            ->assertJsonPath('data.promotion', null);
    }

    public function test_respons_diskon_manipulatif_dari_catalog_fail_closed(): void
    {
        $this->fakeCatalog([
            'discount_total' => 999999,
            'promotion' => [
                'id' => (string) Str::uuid(),
                'name' => 'Rusak',
                'template' => 'order_fixed',
            ],
        ]);

        $this->postJson('/api/orders', $this->payload())
            ->assertServiceUnavailable();

        $this->assertDatabaseCount('orders', 0);
    }

    private function fakeCatalog(array $promotion): void
    {
        Http::fake([
            '*/api/menu*' => Http::response(['data' => [[
                'products' => [[
                    'id' => $this->productId,
                    'name' => 'Espresso',
                    'price' => '10000.00',
                ]],
            ]]]),
            '*/api/internal/promotions/evaluate' => Http::response(['data' => $promotion]),
        ]);
    }

    private function payload(array $extra = []): array
    {
        return [
            'qr_token' => $this->table->qr_token,
            'order_type' => 'dine_in',
            'customer_name' => 'Client Demo',
            'items' => [[
                'product_id' => $this->productId,
                'qty' => 2,
            ]],
            ...$extra,
        ];
    }
}
