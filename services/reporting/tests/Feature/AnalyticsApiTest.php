<?php

namespace Tests\Feature;

use App\Messaging\OrderPaidConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->productId = (string) Str::uuid();
    }

    public function test_endpoint_laporan_wajib_token_owner(): void
    {
        $this->getJson('/api/summary?from=2026-07-20&to=2026-07-23')
            ->assertUnauthorized();

        $this->withToken($this->token('cashier'))
            ->getJson('/api/summary?from=2026-07-20&to=2026-07-23')
            ->assertForbidden();

        $this->withToken($this->token(tenantId: 'tenant-tidak-valid'))
            ->getJson('/api/summary?from=2026-07-20&to=2026-07-23')
            ->assertUnauthorized();
    }

    public function test_summary_menghitung_paid_dan_mengisolasi_outlet(): void
    {
        $consumer = app(OrderPaidConsumer::class);
        $consumer->handle($this->event('2026-07-20T10:00:00+07:00', 20000, 'cash'));
        $consumer->handle($this->event('2026-07-21T10:00:00+07:00', 30000, 'qris_static'));
        $consumer->handle($this->event(
            '2026-07-21T11:00:00+07:00',
            90000,
            'cash',
            outletId: (string) Str::uuid(),
        ));

        $this->withToken($this->token())
            ->getJson('/api/summary?from=2026-07-20&to=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.revenue', 50000)
            ->assertJsonPath('data.gross_subtotal', 50000)
            ->assertJsonPath('data.discount_total', 0)
            ->assertJsonPath('data.net_subtotal', 50000)
            ->assertJsonPath('data.transactions', 2)
            ->assertJsonPath('data.average_order_value', 25000)
            ->assertJsonFragment([
                'method' => 'cash',
                'transactions' => 1,
                'revenue' => 20000,
            ])
            ->assertJsonFragment([
                'method' => 'qris_static',
                'transactions' => 1,
                'revenue' => 30000,
            ]);
    }

    public function test_tren_harian_dan_produk_terlaris(): void
    {
        $consumer = app(OrderPaidConsumer::class);
        $consumer->handle($this->event('2026-07-20T10:00:00+07:00', 20000, 'cash', qty: 2));
        $consumer->handle($this->event('2026-07-21T10:00:00+07:00', 30000, 'qris_static', qty: 3));

        $token = $this->token();

        $this->withToken($token)
            ->getJson('/api/trends/daily?from=2026-07-20&to=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.points.0.date', '2026-07-20')
            ->assertJsonPath('data.points.0.revenue', 20000)
            ->assertJsonPath('data.points.1.date', '2026-07-21')
            ->assertJsonPath('data.points.1.revenue', 30000);

        $this->withToken($token)
            ->getJson('/api/products/top?from=2026-07-20&to=2026-07-21&limit=5')
            ->assertOk()
            ->assertJsonPath('data.products.0.product_id', $this->productId)
            ->assertJsonPath('data.products.0.product_name', 'Espresso')
            ->assertJsonPath('data.products.0.qty', 5)
            ->assertJsonPath('data.products.0.revenue', 50000);
    }

    public function test_semua_endpoint_mengisolasi_tenant_meski_outlet_id_sama(): void
    {
        $otherTenantId = (string) Str::uuid();
        $consumer = app(OrderPaidConsumer::class);

        $consumer->handle($this->event(
            '2026-07-21T10:00:00+07:00',
            20000,
            'cash',
            qty: 2,
        ));
        $consumer->handle($this->event(
            '2026-07-21T11:00:00+07:00',
            90000,
            'qris_static',
            tenantId: $otherTenantId,
            qty: 9,
        ));

        $tenantAToken = $this->token();

        $this->withToken($tenantAToken)
            ->getJson('/api/summary?from=2026-07-21&to=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.revenue', 20000)
            ->assertJsonPath('data.transactions', 1);

        $this->withToken($tenantAToken)
            ->getJson('/api/trends/daily?from=2026-07-21&to=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.points.0.revenue', 20000)
            ->assertJsonPath('data.points.0.transactions', 1);

        $this->withToken($tenantAToken)
            ->getJson('/api/products/top?from=2026-07-21&to=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.products.0.qty', 2)
            ->assertJsonPath('data.products.0.revenue', 20000);

        $this->withToken($this->token(tenantId: $otherTenantId))
            ->getJson('/api/summary?from=2026-07-21&to=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.revenue', 90000)
            ->assertJsonPath('data.transactions', 1);
    }

    public function test_rentang_laporan_dibatasi_maksimal_366_hari(): void
    {
        $this->withToken($this->token())
            ->getJson('/api/summary?from=2025-01-01&to=2026-07-23')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_performa_promo_dihitung_dan_dibatasi_tenant_outlet(): void
    {
        $promotionId = (string) Str::uuid();
        $consumer = app(OrderPaidConsumer::class);
        $consumer->handle($this->event(
            '2026-07-21T10:00:00+07:00',
            15000,
            'cash',
            discount: 5000,
            promotionId: $promotionId,
        ));
        $consumer->handle($this->event(
            '2026-07-21T11:00:00+07:00',
            90000,
            'cash',
            outletId: (string) Str::uuid(),
            discount: 10000,
            promotionId: (string) Str::uuid(),
        ));

        $this->withToken($this->token())
            ->getJson('/api/promotions/performance?from=2026-07-21&to=2026-07-21')
            ->assertOk()
            ->assertJsonCount(1, 'data.promotions')
            ->assertJsonPath('data.promotions.0.promotion_id', $promotionId)
            ->assertJsonPath('data.promotions.0.promotion_name', 'Diskon Launching')
            ->assertJsonPath('data.promotions.0.transactions', 1)
            ->assertJsonPath('data.promotions.0.gross_subtotal', 20000)
            ->assertJsonPath('data.promotions.0.discount_total', 5000)
            ->assertJsonPath('data.promotions.0.net_subtotal', 15000)
            ->assertJsonPath('data.promotions.0.revenue', 15000);
    }

    private function event(
        string $occurredAt,
        int $subtotal,
        string $paymentMethod,
        ?string $outletId = null,
        int $qty = 1,
        ?string $tenantId = null,
        int $discount = 0,
        ?string $promotionId = null,
    ): array {
        $grossSubtotal = $subtotal + $discount;
        $payload = [
            'order_id' => (string) Str::uuid(),
            'payment_method' => $paymentMethod,
            'totals' => [
                'gross_subtotal' => $grossSubtotal,
                'discount_total' => $discount,
                'subtotal' => $subtotal,
                'service_charge' => 0,
                'tax' => 0,
                'grand_total' => $subtotal,
            ],
            'items' => [[
                'product_id' => $this->productId,
                'product_name' => 'Espresso',
                'qty' => $qty,
                'unit_price' => intdiv($grossSubtotal, $qty),
            ]],
        ];
        if ($promotionId !== null) {
            $payload['promotion'] = [
                'id' => $promotionId,
                'name' => 'Diskon Launching',
                'template' => 'order_fixed',
            ];
        }

        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.paid',
            'occurred_at' => $occurredAt,
            'tenant_id' => $tenantId ?? $this->tenantId,
            'outlet_id' => $outletId ?? $this->outletId,
            'payload' => $payload,
        ];
    }

    private function token(
        string $role = 'owner',
        ?string $tenantId = null,
        ?string $outletId = null,
    ): string {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'sub' => (string) Str::uuid(),
            'tenant_id' => $tenantId ?? $this->tenantId,
            'outlet_id' => $outletId ?? $this->outletId,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signingInput = "{$header}.{$payload}";
        $privateKey = file_get_contents(base_path('../iam/storage/keys/jwt-private.pem'));
        $this->assertIsString($privateKey);
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $this->assertTrue($signed);

        return "{$signingInput}.{$this->base64Url($signature)}";
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
