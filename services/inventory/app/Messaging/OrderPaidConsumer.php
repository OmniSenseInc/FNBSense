<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Exceptions\CatalogUnavailableException;
use App\Models\ProcessedOrder;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\CatalogRecipeClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    // Presisi kolom qty_on_hand / min_stock (decimal 14,3) — perbandingan ambang
    // dibulatkan ke sini supaya galat float tak membalik keputusan tepat di batas.
    private const QTY_SCALE = 3;

    public function __construct(
        private readonly CatalogRecipeClient $catalog,
        private readonly EventPublisher $publisher,
        private readonly ProcessedOrderGate $gate,
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
        //    Dedup TIDAK lagi ditebak dari jenis exception: unique(
        //    outlet_id, ingredient_id) di stock_balances bisa bentrok untuk dua
        //    order BERBEDA yang balapan membuat baris saldo baru — ACK di situ
        //    = potongan stok hilang permanen, diam-diam. Pagar yang sah hanya
        //    unique(order_id) di processed_orders, dan ia diputus di dalam
        //    deduct() lewat ProcessedOrderGate::claim().
        try {
            $result = $this->deduct($envelope, $recipes);
        } catch (DuplicateProcessedOrderException $e) {
            // Race: consumer lain menandai processed di sela cek dedup & commit.
            // Transaksi kita rollback penuh → tak ada potong dobel. Aman di-ACK.
            return ConsumeOutcome::Ack;
        } catch (QueryException $e) {
            // Galat DB transient (deadlock/koneksi) → requeue, jangan tandai processed.
            Log::warning("inventory.consume: galat DB, requeue order {$orderId}.");

            return ConsumeOutcome::Requeue;
        }

        // 5. Saga (F4c): terbitkan DI LUAR transaksi, setelah commit. Kegagalan
        //    publish tak boleh menggagalkan potong yang sudah sah.
        $this->publishSaga($envelope, $result);

        return ConsumeOutcome::Ack;
    }

    /**
     * Terbitkan event saga hasil deduksi. Potong stok SUDAH commit di titik ini,
     * jadi gagal publish → catat error & jalan terus: requeue percuma (bakal
     * ke-dedup jadi ACK) dan melempar exception cuma menumbangkan daemon.
     * Kondisinya tetap terbaca dari processed_orders + saldo, jadi tak hilang senyap.
     *
     * @param  array{status: string, shortfall: array<int, array<string, mixed>>, low_stock: array<int, array<string, mixed>>, unmapped: array<int, string>}  $result
     */
    private function publishSaga(array $envelope, array $result): void
    {
        $orderId = $envelope['payload']['order_id'];

        $events = [];
        if ($result['unmapped'] !== []) {
            $events['inventory.recipe_missing'] = ['order_id' => $orderId, 'product_ids' => $result['unmapped']];
        }
        if ($result['shortfall'] !== []) {
            $events['inventory.shortfall'] = ['order_id' => $orderId, 'items' => $result['shortfall']];
        }
        if ($result['low_stock'] !== []) {
            $events['inventory.low_stock'] = ['order_id' => $orderId, 'items' => $result['low_stock']];
        }

        foreach ($events as $eventType => $payload) {
            try {
                $this->publisher->publish($eventType, $envelope['tenant_id'], $envelope['outlet_id'], $payload);
            } catch (Throwable $e) {
                Log::error("inventory.consume: gagal terbitkan {$eventType} order {$orderId}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Potong saldo sesuai resep, tulis ledger, tandai processed — SATU transaksi.
     * Balikkan detail untuk saga (F4c); event-nya diterbitkan setelah commit.
     *
     * @param  array<string, array<int, array{ingredient_id: string, qty_per_unit: mixed, unit: ?string}>>  $recipes
     * @return array{status: string, shortfall: array<int, array<string, mixed>>, low_stock: array<int, array<string, mixed>>, unmapped: array<int, string>}
     */
    private function deduct(array $envelope, array $recipes): array
    {
        return DB::transaction(function () use ($envelope, $recipes) {
            $tenantId = $envelope['tenant_id'];
            $outletId = $envelope['outlet_id'];
            $orderId = $envelope['payload']['order_id'];

            $unmapped = []; // product_id => true (kunci = anti-duplikat kalau produk sama 2x)

            // Agregasi kebutuhan per bahan lintas item (bahan sama di 2 item → 1 movement).
            $needed = []; // ingredient_id => total qty dipotong
            foreach ($envelope['payload']['items'] as $item) {
                $productId = $item['product_id'];

                // Produk tanpa resep dari Catalog → unmapped (#9): skip, JANGAN tumbangkan.
                if (! isset($recipes[$productId])) {
                    $unmapped[$productId] = true;

                    continue;
                }

                foreach ($recipes[$productId] as $ing) {
                    $ingredientId = $ing['ingredient_id'];
                    $perUnit = (float) $ing['qty_per_unit'];
                    $needed[$ingredientId] = ($needed[$ingredientId] ?? 0.0) + $perUnit * (float) $item['qty'];
                }
            }

            $anyNegative = false; // kondisi akhir → status baris processed_orders
            $shortfall = [];      // yang BARU melintas ke minus → event
            $lowStock = [];       // yang BARU melintas ambang min_stock → event

            foreach ($needed as $ingredientId => $qtyToDeduct) {
                $balance = $this->lockOrNewBalance($tenantId, $outletId, $ingredientId);

                // Dibulatkan ke presisi kolom (decimal 14,3) SEBELUM dibandingkan:
                // qty_per_unit pecahan (0.1, 0.15) menumpuk galat float, dan tepat di
                // ambang galat sekecil apa pun membalik hasil `<` / `<=` → alert meleset
                // atau terbit palsu. Bandingkan pada presisi yang sama dengan yang disimpan.
                $before = round((float) $balance->qty_on_hand, self::QTY_SCALE);
                $minStock = round((float) $balance->min_stock, self::QTY_SCALE);
                // Stok kurang → saldo BOLEH negatif (#7): sinyal jujur "utang stok".
                $after = round($before - $qtyToDeduct, self::QTY_SCALE);

                $balance->qty_on_hand = $after;
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

                if ($after < 0) {
                    $anyNegative = true;
                }

                // Event ditembakkan saat MELINTAS ambang, bukan saat BERADA di bawahnya.
                // Sekali bahan jebol, order-order berikutnya tak meneriakkan bahan yang
                // sama — kalau tidak, satu bahan habis = puluhan alert kembar dan owner
                // mematikan notifikasinya. Cukup bandingkan before/after yang sudah di tangan.
                if ($before >= 0 && $after < 0) {
                    $shortfall[] = [
                        'ingredient_id' => $ingredientId,
                        'needed' => $qtyToDeduct,
                        'on_hand_after' => $after,
                    ];
                } elseif ($minStock > 0 && $before > $minStock && $after <= $minStock) {
                    // Hanya kalau owner sudah menetapkan ambang (default kolom 0 = diam),
                    // dan tidak dobel dengan shortfall — minus sudah kabar yang lebih parah.
                    $lowStock[] = [
                        'ingredient_id' => $ingredientId,
                        'on_hand_after' => $after,
                        'min_stock' => $minStock,
                    ];
                }
            }

            // Status = KONDISI order ini (saldo berakhir minus?), bukan "baru melintas".
            // Sengaja beda dari pemicu event: tabel mencatat keadaan, event mencatat kejadian.
            $status = $unmapped !== [] ? 'recipe_missing' : ($anyNegative ? 'shortfall' : 'deducted');

            // Pagar idempotensi: unique(order_id) di processed_orders, DITANYA
            // ke DB lewat gate — bukan ditebak dari jenis exception. Bentrokan
            // = duplikat sungguhan (consumer lain menang) → lempar sinyal khusus
            // yang di-handle() diterjemahkan jadi ACK; galat DB lain (termasuk
            // bentrokan stock_balances saat dua order beda balapan) tetap naik
            // sebagai QueryException → Requeue → potongan TIDAK pernah hilang.
            if (! $this->gate->claim($orderId, $tenantId, $outletId, $status)) {
                throw new DuplicateProcessedOrderException($orderId);
            }

            if ($status !== 'deducted') {
                Log::warning("inventory.consume: order {$orderId} status={$status}.");
            }

            return [
                'status' => $status,
                'shortfall' => $shortfall,
                'low_stock' => $lowStock,
                'unmapped' => array_keys($unmapped),
            ];
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
            'min_stock' => 0, // eksplisit: 0 = owner belum set ambang → low_stock diam
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

        // Id scoping WAJIB string tak-kosong. `empty()` meloloskan non-scalar
        // (array/objek dari bug serialisasi) → meledak sbg TypeError saat
        // INSERT — bukan QueryException → tidak tertangkap → menumbangkan
        // daemon (kelas bug yang sama yang sudah dibereskan di finance).
        if (! $this->nonEmptyString($e['tenant_id'] ?? null)
            || ! $this->nonEmptyString($e['outlet_id'] ?? null)) {
            return false;
        }

        // occurred_at WAJIB tanggal yang bisa di-parse: string sembarang lolos
        // ke kolom timestamp → QueryException → requeue selamanya (pesan racun).
        if (! is_string($e['occurred_at'] ?? null) || ! $this->parsableDate($e['occurred_at'])) {
            return false;
        }

        $payload = $e['payload'] ?? null;
        if (! is_array($payload) || ! $this->nonEmptyString($payload['order_id'] ?? null) || ! is_array($payload['items'] ?? null)) {
            return false;
        }

        foreach ($payload['items'] as $item) {
            if (! is_array($item) || ! $this->nonEmptyString($item['product_id'] ?? null) || ! isset($item['qty']) || ! is_numeric($item['qty'])) {
                return false;
            }

            // qty WAJIB positif. is_numeric saja meloloskan "-2" (potong minus =
            // MENAMBAH stok — racun paling licik) dan "2.5" (pecahan di luar
            // kontrak qty integer). Nol juga sia-sia: order tanpa takaran.
            if ((float) $item['qty'] <= 0) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $v): bool
    {
        return is_string($v) && $v !== '';
    }

    private function parsableDate(string $v): bool
    {
        try {
            \Carbon\Carbon::parse($v);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
