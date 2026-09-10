<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderType;
use App\Exceptions\CatalogUnavailableException;
use App\Models\Order;
use App\Models\OrderSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Buat order PENDING dari daftar item — dipakai customer (OrderController) dan
 * kasir (CashierOrderController). Harga & total SELALU dihitung server, tak
 * pernah dari client; logika uang ini cuma boleh punya SATU tempat.
 */
class OrderPlacement
{
    /** Berapa kali transaksi diulang kalau order_number acak kebetulan bentrok. */
    private const MAX_ORDER_NUMBER_ATTEMPTS = 5;

    public function __construct(
        private readonly CatalogClient $catalog,
        private readonly PromotionClient $promotions,
        private readonly InventoryClient $inventory,
        private readonly ProductCostClient $costs,
    ) {}

    /**
     * @param array<int, array{product_id: string, qty: int, note?: ?string}> $items
     */
    public function place(
        string $tenantId,
        string $outletId,
        ?string $tableId,
        string $orderType,
        string $customerName,
        array $items,
        ?string $paymentPreference = null,
    ): Order {
        // Harga SELALU dari Catalog, tak pernah dari client. Catalog down -> 503.
        $products = $this->catalog->productsForTenant($tenantId);

        // Gerbang stok SEBELUM uang berpindah: bahan habis -> 422, bukan saldo
        // negatif di belakang.
        $habis = $this->inventory->unavailableProducts($tenantId, $outletId, $items);
        if ($habis !== []) {
            $nama = array_map(
                fn (string $id) => $products[$id]['name'] ?? 'Produk',
                $habis,
            );

            throw new HttpException(422, 'Bahan untuk '.implode(', ', $nama).' sedang habis. Silakan pilih menu lain.');
        }

        // Tarif di-snapshot dari setting outlet; belum diset -> tarif 0 (bukan tebak).
        $setting = OrderSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('outlet_id', $outletId)
            ->first()
            ?? OrderSetting::defaultsFor($tenantId, $outletId);

        // HPP (harga pokok) di-snapshot dari Catalog. Best-effort: kalau gagal,
        // order TETAP jalan dengan HPP 0 — yang meleset cuma laporan margin,
        // bukan uang yang diterima kasir.
        $productIds = array_values(array_unique(array_map(
            fn (array $item) => $item['product_id'],
            $items,
        )));
        $productCosts = [];
        try {
            $productCosts = $this->costs->unitCosts($tenantId, $productIds);
        } catch (CatalogUnavailableException $e) {
            Log::warning('order: harga pokok tak tersedia, lanjut dengan HPP 0.', ['error' => $e->getMessage()]);
        }

        // Produk di luar menu tenant -> ProductNotOrderableException -> 422.
        $calculator = new OrderCalculator;
        $calc = $calculator->calculate($items, $products, $setting, $productCosts);

        if ((bool) config('services.catalog.promotions_enabled', true)) {
            $promotion = $this->promotions->evaluate($tenantId, $outletId, $calc);
            $calc = $calculator->applyPromotion($calc, $promotion, $setting);
        }

        return $this->persist($tenantId, $outletId, $tableId, $orderType, $customerName, $paymentPreference, $setting, $calc);
    }

    /**
     * Tulis order PENDING + itemnya dalam SATU transaksi.
     *
     * order_number acak bisa (sangat jarang) menabrak unique(outlet_id, order_number).
     * Tangkap SPESIFIK UniqueConstraintViolationException lalu ulangi transaksi
     * dengan nomor baru — bukan 500 mentah. Batas percobaan mencegah loop abadi.
     */
    private function persist(
        string $tenantId,
        string $outletId,
        ?string $tableId,
        string $orderType,
        string $customerName,
        ?string $paymentPreference,
        OrderSetting $setting,
        array $calc,
    ): Order {
        $isTakeaway = $orderType === OrderType::Takeaway->value;
        // Takeaway = tanpa meja (skema table_id nullable).
        $effectiveTableId = $isTakeaway ? null : $tableId;
        $expiresAt = now()->addMinutes((int) $setting->order_expiry_minutes);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($tenantId, $outletId, $effectiveTableId, $orderType, $customerName, $paymentPreference, $setting, $calc, $expiresAt) {
                    // Hanya kolom milik-customer yang mass-assignable; sisanya di-set
                    // eksplisit — uang/status/identitas tak boleh dari body.
                    $order = new Order([
                        'order_type' => $orderType,
                        'customer_name' => $customerName,
                        'payment_preference' => $paymentPreference,
                    ]);

                    $order->tenant_id = $tenantId;
                    $order->outlet_id = $outletId;
                    $order->table_id = $effectiveTableId;
                    $order->order_number = (new OrderNumberGenerator)->generate();
                    $order->gross_subtotal = $calc['gross_subtotal'];
                    $order->discount_total = $calc['discount_total'];
                    $order->subtotal = $calc['subtotal'];
                    $order->service_charge = $calc['service_charge'];
                    $order->tax = $calc['tax'];
                    $order->grand_total = $calc['grand_total'];
                    // Tarif di-snapshot: owner ubah tarif besok != ubah struk ini.
                    $order->tax_percent = $setting->tax_percent;
                    $order->service_charge_percent = $setting->service_charge_percent;
                    $order->promotion_id = $calc['promotion']['id'] ?? null;
                    $order->promotion_snapshot = $calc['promotion'];
                    $order->expires_at = $expiresAt;
                    $order->save();

                    foreach ($calc['items'] as $line) {
                        $order->items()->create($line);
                    }

                    return $order->load('items');
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ORDER_NUMBER_ATTEMPTS) {
                    throw $e;
                }
                // order_number bentrok -> ulangi transaksi dengan nomor baru.
            }
        }
    }
}
