<?php

namespace App\Http\Controllers;

use App\Enums\OrderType;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSetting;
use App\Models\Table;
use App\Services\CatalogClient;
use App\Services\OrderCalculator;
use App\Services\OrderNumberGenerator;
use App\Services\PromotionClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint customer — PUBLIK, tanpa login, rate-limited.
 *
 * Beda mendasar dari controller owner/kasir: tenant & outlet TIDAK dari klaim JWT
 * (customer tak punya token) melainkan diturunkan dari qr_token meja yang di-scan.
 * Itulah yang membuat customer secara STRUKTURAL tak bisa memesan lintas tenant
 * (skrutini #2): dia tak pernah menyebut tenant/outlet, hanya menunjuk meja.
 */
class OrderController extends Controller
{
    /** Berapa kali transaksi diulang kalau order_number acak kebetulan bentrok. */
    private const MAX_ORDER_NUMBER_ATTEMPTS = 5;

    /**
     * Resolve QR meja -> identitas tenant/outlet/meja untuk frontend.
     *
     * Token tak dikenal / meja non-aktif -> 404 (firstOrFail). Sengaja 404, bukan
     * 403: jangan bocorkan bahwa token itu "ada tapi mati".
     */
    public function showTable(string $qrToken): JsonResponse
    {
        $table = Table::query()
            ->where('qr_token', $qrToken)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json(['data' => [
            'tenant_id' => $table->tenant_id,
            'outlet_id' => $table->outlet_id,
            'table_id' => $table->id,
            'label' => $table->label,
        ]]);
    }

    /**
     * Buat order PENDING. Harga & total DIHITUNG SERVER, tak pernah dari client.
     */
    public function store(
        StoreOrderRequest $request,
        CatalogClient $catalog,
        PromotionClient $promotions,
    ): JsonResponse {
        $data = $request->validated();

        // Meja penentu tenant+outlet. Non-aktif/tak dikenal -> 404, bukan 422:
        // token bukan "input salah format" melainkan "meja tak ada / mati".
        $table = Table::query()
            ->where('qr_token', $data['qr_token'])
            ->where('is_active', true)
            ->firstOrFail();

        // Harga SELALU dari Catalog, tak pernah dari client. Catalog down -> 503.
        $products = $catalog->productsForTenant($table->tenant_id);

        // Tarif di-snapshot dari setting outlet; belum diset -> tarif 0 (bukan tebak).
        $setting = OrderSetting::query()
            ->where('tenant_id', $table->tenant_id)
            ->where('outlet_id', $table->outlet_id)
            ->first()
            ?? OrderSetting::defaultsFor($table->tenant_id, $table->outlet_id);

        // Produk di luar menu tenant -> ProductNotOrderableException -> 422.
        $calculator = new OrderCalculator;
        $calc = $calculator->calculate($data['items'], $products, $setting);

        if ((bool) config('services.catalog.promotions_enabled', true)) {
            $promotion = $promotions->evaluate(
                $table->tenant_id,
                $table->outlet_id,
                $calc,
            );
            $calc = $calculator->applyPromotion($calc, $promotion, $setting);
        }

        $order = $this->persistOrder($table, $data, $setting, $calc);

        return response()->json(['data' => $this->present($order)], 201);
    }

    /**
     * Polling status oleh customer. Field TERBATAS (skrutini): tanpa confirmed_by,
     * tenant_id, outlet_id — itu bukan urusan customer.
     */
    public function show(string $id): JsonResponse
    {
        $order = Order::with('items')->findOrFail($id);

        return response()->json(['data' => $this->present($order)]);
    }

    /**
     * Tulis order PENDING + itemnya dalam SATU transaksi.
     *
     * order_number acak bisa (sangat jarang) menabrak unique(outlet_id, order_number).
     * Tangkap SPESIFIK UniqueConstraintViolationException lalu ulangi transaksi
     * dengan nomor baru — bukan 500 mentah ke customer. Batas percobaan mencegah
     * loop tak berujung kalau bentroknya ternyata karena sebab lain.
     */
    private function persistOrder(Table $table, array $data, OrderSetting $setting, array $calc): Order
    {
        $isTakeaway = $data['order_type'] === OrderType::Takeaway->value;
        // Takeaway = tanpa meja (skema table_id nullable). Meja yang di-scan cuma
        // penentu tenant/outlet, tak melekat ke ordernya.
        $tableId = $isTakeaway ? null : $table->id;
        $expiresAt = now()->addMinutes((int) $setting->order_expiry_minutes);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($table, $data, $setting, $calc, $tableId, $expiresAt) {
                    // Hanya kolom milik-customer yang mass-assignable; sisanya di-set
                    // eksplisit di bawah — uang/status/identitas tak boleh dari body.
                    $order = new Order([
                        'order_type' => $data['order_type'],
                        'customer_name' => $data['customer_name'],
                    ]);

                    $order->tenant_id = $table->tenant_id;
                    $order->outlet_id = $table->outlet_id;
                    $order->table_id = $tableId;
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

    /**
     * Bentuk publik order: cukup untuk struk & polling, TANPA confirmed_by /
     * tenant_id / outlet_id / payment_method internal.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'gross_subtotal' => $order->gross_subtotal,
            'discount_total' => $order->discount_total,
            'subtotal' => $order->subtotal,
            'service_charge' => $order->service_charge,
            'tax' => $order->tax,
            'grand_total' => $order->grand_total,
            'promotion' => $order->promotion_snapshot,
            'expires_at' => $order->expires_at,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit_price' => $item->unit_price,
                'qty' => $item->qty,
                'line_total' => $item->line_total,
                'note' => $item->note,
            ])->all(),
        ];
    }
}
