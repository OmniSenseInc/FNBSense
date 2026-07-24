<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PromotionStatus;
use App\Enums\PromotionTemplate;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    private string $tenantId;

    private string $outletId;

    private Product $espresso;

    private Product $latte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->outletId = (string) Str::uuid();
        $this->espresso = Product::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Espresso',
            'price' => 10000,
        ]);
        $this->latte = Product::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Latte',
            'price' => 25000,
        ]);
    }

    public function test_owner_mendapat_template_tetapi_kasir_ditolak(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId, outletId: $this->outletId))
            ->getJson('/api/promotion-templates')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.key', PromotionTemplate::OrderPercentage->value)
            ->assertJsonPath('data.0.required_fields.1', 'percentage')
            ->assertJsonPath('data.0.supports.products', false);

        $this->withHeaders($this->authHeaders(
            $this->tenantId,
            role: 'cashier',
            outletId: $this->outletId,
        ))
            ->getJson('/api/promotion-templates')
            ->assertForbidden();
    }

    public function test_promo_mengambil_tenant_dan_outlet_dari_jwt_bukan_body(): void
    {
        $response = $this->withHeaders($this->authHeaders(
            $this->tenantId,
            outletId: $this->outletId,
        ))->postJson('/api/promotions', [
            ...$this->orderPercentagePayload(),
            'tenant_id' => (string) Str::uuid(),
            'outlet_id' => (string) Str::uuid(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.tenant_id', $this->tenantId)
            ->assertJsonPath('data.outlet_id', $this->outletId)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('promotions', [
            'id' => $response->json('data.id'),
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
        ]);
    }

    public function test_target_produk_tenant_lain_ditolak(): void
    {
        $otherProduct = Product::create([
            'tenant_id' => (string) Str::uuid(),
            'name' => 'Milik tenant lain',
            'price' => 1000,
        ]);

        $this->withHeaders($this->authHeaders($this->tenantId, outletId: $this->outletId))
            ->postJson('/api/promotions', [
                'name' => 'Diskon Produk',
                'template' => PromotionTemplate::ProductPercentage->value,
                'percentage' => 10,
                'products' => [['product_id' => $otherProduct->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('products.0.product_id');
    }

    public function test_promo_tenant_atau_outlet_lain_tidak_bisa_diakses(): void
    {
        $promotion = $this->promotion();

        $this->withHeaders($this->authHeaders(
            (string) Str::uuid(),
            outletId: $this->outletId,
        ))
            ->getJson("/api/promotions/{$promotion->id}")
            ->assertNotFound();

        $this->withHeaders($this->authHeaders(
            $this->tenantId,
            outletId: (string) Str::uuid(),
        ))
            ->getJson("/api/promotions/{$promotion->id}")
            ->assertNotFound();
    }

    public function test_owner_tanpa_outlet_tidak_bisa_mengelola_promo(): void
    {
        $this->withHeaders($this->authHeaders($this->tenantId))
            ->postJson('/api/promotions', $this->orderPercentagePayload())
            ->assertForbidden();
    }

    public function test_lifecycle_activate_pause_dan_hapus(): void
    {
        $promotion = $this->promotion();
        $headers = $this->authHeaders($this->tenantId, outletId: $this->outletId);

        $this->withHeaders($headers)
            ->postJson("/api/promotions/{$promotion->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->withHeaders($headers)
            ->deleteJson("/api/promotions/{$promotion->id}")
            ->assertConflict();

        $this->withHeaders($headers)
            ->postJson("/api/promotions/{$promotion->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->withHeaders($headers)
            ->deleteJson("/api/promotions/{$promotion->id}")
            ->assertOk();

        $this->assertDatabaseMissing('promotions', ['id' => $promotion->id]);
    }

    public function test_evaluator_memilih_diskon_terbesar_dan_mengisolasi_tenant(): void
    {
        $this->promotion([
            'name' => 'Diskon 10%',
            'template' => PromotionTemplate::OrderPercentage,
            'percentage' => 10,
            'status' => PromotionStatus::Active,
        ]);
        $winner = $this->promotion([
            'name' => 'Potong 8K',
            'template' => PromotionTemplate::OrderFixed,
            'amount' => 8000,
            'status' => PromotionStatus::Active,
        ]);

        $this->evaluate()
            ->assertOk()
            ->assertJsonPath('data.discount_total', 8000)
            ->assertJsonPath('data.promotion.id', $winner->id);

        $this->evaluate(tenantId: (string) Str::uuid())
            ->assertOk()
            ->assertJsonPath('data.discount_total', 0)
            ->assertJsonPath('data.promotion', null);
    }

    public function test_bundle_menghitung_jumlah_paket_lengkap(): void
    {
        $bundle = $this->promotion([
            'name' => 'Paket Kopi',
            'template' => PromotionTemplate::BundleFixedPrice,
            'amount' => 30000,
            'status' => PromotionStatus::Active,
        ]);
        $bundle->products()->createMany([
            ['product_id' => $this->espresso->id, 'required_qty' => 1],
            ['product_id' => $this->latte->id, 'required_qty' => 1],
        ]);

        $this->evaluate([
            [
                'product_id' => $this->espresso->id,
                'unit_price' => 10000,
                'qty' => 2,
                'line_total' => 20000,
            ],
            [
                'product_id' => $this->latte->id,
                'unit_price' => 25000,
                'qty' => 2,
                'line_total' => 50000,
            ],
        ], 70000)
            ->assertOk()
            ->assertJsonPath('data.discount_total', 10000)
            ->assertJsonPath('data.promotion.template', 'bundle_fixed_price');
    }

    public function test_evaluator_menolak_service_token_salah_dan_total_manipulatif(): void
    {
        $this->postJson('/api/internal/promotions/evaluate', [
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
            'subtotal' => 10000,
            'items' => [],
        ])->assertUnauthorized();

        $this->withHeader('X-Service-Token', 'test-secret-catalog')
            ->postJson('/api/internal/promotions/evaluate', [
                'tenant_id' => $this->tenantId,
                'outlet_id' => $this->outletId,
                'subtotal' => 1,
                'items' => [[
                    'product_id' => $this->espresso->id,
                    'unit_price' => 10000,
                    'qty' => 1,
                    'line_total' => 10000,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subtotal');
    }

    private function promotion(array $override = []): Promotion
    {
        $promotion = new Promotion([
            'name' => $override['name'] ?? 'Promo Demo',
            'template' => $override['template'] ?? PromotionTemplate::OrderPercentage,
            'percentage' => $override['percentage'] ?? 10,
            'amount' => $override['amount'] ?? null,
            'priority' => $override['priority'] ?? 100,
        ]);
        $promotion->tenant_id = $override['tenant_id'] ?? $this->tenantId;
        $promotion->outlet_id = $override['outlet_id'] ?? $this->outletId;
        $promotion->status = $override['status'] ?? PromotionStatus::Draft;
        $promotion->save();

        return $promotion;
    }

    private function orderPercentagePayload(): array
    {
        return [
            'name' => 'Diskon Launching',
            'template' => PromotionTemplate::OrderPercentage->value,
            'percentage' => 10,
            'min_subtotal' => 20000,
            'max_discount' => 15000,
            'priority' => 100,
        ];
    }

    private function evaluate(
        ?array $items = null,
        int $subtotal = 35000,
        ?string $tenantId = null,
    ) {
        $items ??= [
            [
                'product_id' => $this->espresso->id,
                'unit_price' => 10000,
                'qty' => 1,
                'line_total' => 10000,
            ],
            [
                'product_id' => $this->latte->id,
                'unit_price' => 25000,
                'qty' => 1,
                'line_total' => 25000,
            ],
        ];

        return $this->withHeader('X-Service-Token', 'test-secret-catalog')
            ->postJson('/api/internal/promotions/evaluate', [
                'tenant_id' => $tenantId ?? $this->tenantId,
                'outlet_id' => $this->outletId,
                'subtotal' => $subtotal,
                'items' => $items,
            ]);
    }
}
