<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Exceptions\CatalogUnavailableException;
use App\Models\ProcessedOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\CatalogRecipeClient;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inti consumer order.paid (F4b) — dipisah dari command supaya bisa diuji tanpa
 * broker. Kontrak perilaku: docs/INVENTORY.md "Kontrak consumer".
 *
 * Invarian uang: PAID → stok, TEPAT SEKALI. Idempotensi dijaga unique(order_id)
 * di processed_orders (pagar DB, bukan cuma kode). Uang sudah masuk → tak ada
 * rollback; stok kurang jadi saldo negatif jujur (saga), bukan alasan gagal.
 */
class OrderPaidConsumer
{
    public function __construct(
        private readonly CatalogRecipeClient $catalog,
    ) {}

    public function handle(array $envelope): ConsumeOutcome
    {
        // 1. Consumer tak percaya pesan mentah — bentuk salah → DLQ, jangan crash.
        if (! $this->validEnvelope($envelope)) {
            Log::warning('inventory.consume: amplop order.paid malformed → DLQ.');

            return ConsumeOutcome::Dead;
        }

        $orderId = $envelope['payload']['order_id'];

        // 2. Dedup jalur cepat: sudah pernah diproses → buang (at-least-once broker).
        if (ProcessedOrder::query()->whereKey($orderId)->exists()) {
            return ConsumeOutcome::Ack;
        }

        // 3. Ambil resep dari Catalog. Catalog down/timeout/non-2xx → requeue, JANGAN
        //    tandai processed (#8): potong cuma TERTUNDA, bukan hilang.
        try {
            $recipes = $this->catalog->recipesForProducts(
                $envelope['tenant_id'],
                $this->uniqueProductIds($envelope['payload']['items']),
            );
        } catch (CatalogUnavailableException $e) {
            Log::warning("inventory.consume: Catalog tak tersedia, requeue order {$orderId}.");

            return ConsumeOutcome::Requeue;
        }

        // 4. Potong dalam satu transaksi (potong + tandai processed = atomik).
        try {
            $this->deduct($envelope, $recipes);
        } catch (UniqueConstraintViolationException $e) {
            // Race: consumer lain sudah menandai processed di sela cek dedup & commit.
            // Transaksi kita rollback penuh → tak ada potong dobel. Aman di-ACK.
            return ConsumeOutcome::Ack;
        } catch (QueryException $e) {
            // Galat DB transient (deadlock/koneksi) → requeue, jangan tandai processed.
            Log::warning("inventory.consume: galat DB, requeue order {$orderId}.");

            return ConsumeOutcome::Requeue;
        }

        // F4c: terbitkan inventory.shortfall / inventory.recipe_missing di sini.
        // F4b cukup: status tercatat di processed_orders + log (lihat deduct()).
        return ConsumeOutcome::Ack;
    }

    /**
     * Potong saldo sesuai resep, tulis ledger, tandai processed — SATU transaksi.
     *
     * @param  array<string, array<int, array{ingredient_id: string, qty_per_unit: mixed, unit: ?string}>>  $recipes
     */
    private function deduct(array $envelope, array $recipes): void
    {
        DB::transaction(function () use ($envelope, $recipes) {
            $tenantId = $envelope['tenant_id'];
            $outletId = $envelope['outlet_id'];
            $orderId = $envelope['payload']['order_id'];

            $unmapped = false;

            // Agregasi kebutuhan per bahan lintas item (bahan sama di 2 item → 1 movement).
            $needed = []; // ingredient_id => total qty dipotong
            foreach ($envelope['payload']['items'] as $item) {
                $productId = $item['product_id'];

                // Produk tanpa resep dari Catalog → unmapped (#9): skip, JANGAN tumbangkan.
                if (! isset($recipes[$productId])) {
                    $unmapped = true;

                    continue;
                }

                foreach ($recipes[$productId] as $ing) {
                    $ingredientId = $ing['ingredient_id'];
                    $perUnit = (float) $ing['qty_per_unit'];
                    $needed[$ingredientId] = ($needed[$ingredientId] ?? 0.0) + $perUnit * (float) $item['qty'];
                }
            }

            $shortfall = false;
            foreach ($needed as $ingredientId => $qtyToDeduct) {
                $balance = $this->lockOrNewBalance($tenantId, $outletId, $ingredientId);
                // Stok kurang → saldo BOLEH negatif (#7): sinyal jujur "utang stok".
                $balance->qty_on_hand = (float) $balance->qty_on_hand - $qtyToDeduct;
                $balance->save();

                StockMovement::create([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'ingredient_id' => $ingredientId,
                    'order_id' => $orderId,
                    'qty_delta' => -$qtyToDeduct, // negatif = potong
                    'reason' => 'order_deduction',
                    'occurred_at' => now(),
                    'created_by' => null, // dari event, bukan user
                ]);

                if ((float) $balance->qty_on_hand < 0) {
                    $shortfall = true;
                }
            }

            $status = $unmapped ? 'recipe_missing' : ($shortfall ? 'shortfall' : 'deducted');

            // Ditulis DI DALAM transaksi → unique(order_id) = pagar idempotensi.
            // Kalau bentrok (race), UniqueConstraintViolationException naik & seluruh
            // potong ikut rollback (ditangkap di handle() → ACK).
            ProcessedOrder::create([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'status' => $status,
                'processed_at' => now(),
            ]);

            if ($status !== 'deducted') {
                Log::warning("inventory.consume: order {$orderId} status={$status} (saga F4c).");
            }
        });
    }

    private function lockOrNewBalance(string $tenantId, string $outletId, string $ingredientId): StockBalance
    {
        $balance = StockBalance::query()
            ->where('outlet_id', $outletId)
            ->where('ingredient_id', $ingredientId)
            ->lockForUpdate()
            ->first();

        return $balance ?? new StockBalance([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'ingredient_id' => $ingredientId,
            'qty_on_hand' => 0,
        ]);
    }

    /**
     * @param  array<int, array{product_id: string, qty: mixed}>  $items
     * @return array<int, string>
     */
    private function uniqueProductIds(array $items): array
    {
        return array_values(array_unique(array_map(
            static fn ($item) => $item['product_id'],
            $items,
        )));
    }

    private function validEnvelope(array $e): bool
    {
        if (($e['event_type'] ?? null) !== 'order.paid') {
            return false;
        }
        if (empty($e['tenant_id']) || empty($e['outlet_id'])) {
            return false;
        }

        $payload = $e['payload'] ?? null;
        if (! is_array($payload) || empty($payload['order_id']) || ! is_array($payload['items'] ?? null)) {
            return false;
        }

        foreach ($payload['items'] as $item) {
            if (! is_array($item) || empty($item['product_id']) || ! isset($item['qty']) || ! is_numeric($item['qty'])) {
                return false;
            }
        }

        return true;
    }
}
