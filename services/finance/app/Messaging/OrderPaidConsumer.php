<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\ProcessedOrder;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inti consumer order.paid (F5b) — dipisah dari command supaya bisa diuji tanpa
 * broker. Finance TAK menghitung ulang uang: semua angka disalin apa adanya dari
 * amplop (Ordering yang otoritatif). Lebih tipis dari Inventory — tak ada panggil
 * Catalog, tak ada saga; cuma mencatat penjualan.
 *
 * Invarian uang: PAID → 1 baris sales, TEPAT SEKALI. Idempotensi dijaga
 * unique(order_id) di processed_orders DAN sales (dua pagar DB, bukan cuma kode).
 */
class OrderPaidConsumer
{
    public function handle(array $envelope): ConsumeOutcome
    {
        // 1. Consumer tak percaya pesan mentah — bentuk salah / uang tak konsisten →
        //    DLQ (permanen), jangan requeue selamanya & jangan tumbangkan daemon.
        if (! $this->validEnvelope($envelope)) {
            Log::warning('finance.consume: amplop order.paid malformed → DLQ.');

            return ConsumeOutcome::Dead;
        }

        $orderId = $envelope['payload']['order_id'];

        // 2. Dedup jalur cepat: sudah pernah dicatat → buang (broker at-least-once).
        if (ProcessedOrder::query()->whereKey($orderId)->exists()) {
            return ConsumeOutcome::Ack;
        }

        // 3. Catat dalam satu transaksi (sales + items + processed = atomik).
        try {
            $this->record($envelope);
        } catch (UniqueConstraintViolationException) {
            // Race: consumer lain sudah menulis sales/processed di sela cek dedup &
            // commit. Transaksi kita rollback penuh → tak ada penjualan dobel. ACK.
            return ConsumeOutcome::Ack;
        } catch (QueryException) {
            // Galat DB transient (deadlock/koneksi) → requeue, jangan tandai processed.
            Log::warning("finance.consume: galat DB, requeue order {$orderId}.");

            return ConsumeOutcome::Requeue;
        }

        return ConsumeOutcome::Ack;
    }

    /**
     * Tulis 1 baris sales + N sale_items + 1 processed_orders — SATU transaksi.
     * order_id unique di dua tabel = pagar idempotensi; race → UniqueConstraint
     * naik & seluruh tulisan rollback (ditangkap di handle() → ACK).
     */
    private function record(array $envelope): void
    {
        DB::transaction(function () use ($envelope) {
            $tenantId = $envelope['tenant_id'];
            $outletId = $envelope['outlet_id'];
            $payload = $envelope['payload'];
            $totals = $payload['totals'];

            $sale = Sale::create([
                'order_id' => $payload['order_id'],
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'subtotal' => (int) $totals['subtotal'],
                'service_charge' => (int) $totals['service_charge'],
                'tax' => (int) $totals['tax'],
                'grand_total' => (int) $totals['grand_total'],
                'payment_method' => $payload['payment_method'] ?? null,
                'paid_at' => $envelope['occurred_at'],
            ]);

            foreach ($payload['items'] as $item) {
                $qty = (int) $item['qty'];
                $unitPrice = (int) $item['unit_price'];

                $sale->items()->create([
                    'product_id' => $item['product_id'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice * $qty, // dihitung ulang, dikunci CHECK di DB
                ]);
            }

            ProcessedOrder::create([
                'order_id' => $payload['order_id'],
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'status' => 'recorded',
                'processed_at' => now(),
            ]);
        });
    }

    /**
     * Amplop valid = bentuk benar DAN uang konsisten. Cek aritmatika total di sini
     * penting: kalau grand_total != subtotal+sc+tax, INSERT sales bakal ditolak
     * CHECK constraint (QueryException) → kalau dibiarkan, jadi pesan racun yang
     * requeue selamanya. Tangkap di gerbang → Dead sekali, bukan loop tak henti.
     */
    private function validEnvelope(array $e): bool
    {
        if (($e['event_type'] ?? null) !== 'order.paid') {
            return false;
        }
        // id scoping WAJIB string tak-kosong. Non-scalar (array/objek dari bug
        // serialisasi) lolos empty() lalu meledak sbg TypeError saat INSERT —
        // bukan QueryException → tak tertangkap → menumbangkan daemon.
        if (! $this->nonEmptyString($e['tenant_id'] ?? null) || ! $this->nonEmptyString($e['outlet_id'] ?? null)) {
            return false;
        }
        // occurred_at WAJIB tanggal yang bisa di-parse. String sembarang lolos ke
        // kolom timestamp → QueryException → requeue selamanya (pesan racun).
        if (! is_string($e['occurred_at'] ?? null) || ! $this->parsableDate($e['occurred_at'])) {
            return false;
        }

        $payload = $e['payload'] ?? null;
        if (! is_array($payload) || ! $this->nonEmptyString($payload['order_id'] ?? null) || ! is_array($payload['items'] ?? null)) {
            return false;
        }

        // Rupiah = integer TAK negatif. is_numeric saja meloloskan "20000.5"/"1e3"
        // (dipotong diam-diam saat cast) dan nilai negatif (ditolak kolom unsigned →
        // racun). Aritmatika total dicek supaya bentrok CHECK sales tak jadi racun.
        $totals = $payload['totals'] ?? null;
        if (! is_array($totals)) {
            return false;
        }
        foreach (['subtotal', 'service_charge', 'tax', 'grand_total'] as $key) {
            if (! $this->nonNegativeInt($totals[$key] ?? null)) {
                return false;
            }
        }
        if ((int) $totals['grand_total'] !== (int) $totals['subtotal'] + (int) $totals['service_charge'] + (int) $totals['tax']) {
            return false;
        }

        foreach ($payload['items'] as $item) {
            if (! is_array($item)
                || ! $this->nonEmptyString($item['product_id'] ?? null)
                || ! $this->nonNegativeInt($item['qty'] ?? null) || (int) $item['qty'] < 1
                || ! $this->nonNegativeInt($item['unit_price'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $v): bool
    {
        return is_string($v) && $v !== '';
    }

    /** Rupiah valid = integer (bukan pecahan/notasi ilmiah) dan tak negatif. */
    private function nonNegativeInt(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_INT) !== false && (int) $v >= 0;
    }

    private function parsableDate(string $v): bool
    {
        try {
            Carbon::parse($v);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
