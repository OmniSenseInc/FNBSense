<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\Notification;
use App\Models\ProcessedEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Mengubah event alarm inventory.* menjadi notifikasi inbox, idempoten by event_id. */
class NotificationConsumer
{
    /** event_type sumber -> type notifikasi singkat. */
    private const TYPES = [
        'inventory.low_stock' => 'low_stock',
        'inventory.shortfall' => 'shortfall',
        'inventory.recipe_missing' => 'recipe_missing',
        'order.cancelled' => 'order_cancelled',
    ];

    /**
     * Siapa yang boleh melihat tiap jenis. Yang tak terdaftar -> semua.
     *
     * Alarm stok untuk semua: kasirlah yang berdiri di depan lemari bahan.
     * Pembatalan hanya untuk owner — ia melaporkan tindakan kasir, dan orang
     * yang dilaporkan tak boleh ikut membacanya.
     */
    private const AUDIENS = [
        'order.cancelled' => Notification::UNTUK_OWNER,
    ];

    public function handle(array $envelope): ConsumeOutcome
    {
        if (! $this->validEnvelope($envelope)) {
            Log::warning('notification.consume: amplop inventory.* malformed -> DLQ.');

            return ConsumeOutcome::Dead;
        }

        $eventId = $envelope['event_id'];
        if (ProcessedEvent::query()->whereKey($eventId)->exists()) {
            return ConsumeOutcome::Ack;
        }

        try {
            $this->record($envelope);
        } catch (UniqueConstraintViolationException) {
            return ConsumeOutcome::Ack; // event kembar (race) -> sudah tercatat
        } catch (QueryException) {
            Log::warning("notification.consume: galat DB, requeue event {$eventId}.");

            return ConsumeOutcome::Requeue;
        }

        return ConsumeOutcome::Ack;
    }

    private function record(array $envelope): void
    {
        DB::transaction(function () use ($envelope): void {
            [$severity, $title, $body] = $this->present($envelope);

            Notification::create([
                'tenant_id' => $envelope['tenant_id'],
                'outlet_id' => $envelope['outlet_id'],
                'audience' => self::AUDIENS[$envelope['event_type']] ?? Notification::UNTUK_SEMUA,
                'type' => self::TYPES[$envelope['event_type']],
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'payload' => $envelope['payload'],
                'source_event_id' => $envelope['event_id'],
                'created_at' => Carbon::parse($envelope['occurred_at']),
            ]);

            ProcessedEvent::create([
                'event_id' => $envelope['event_id'],
                'event_type' => $envelope['event_type'],
                'processed_at' => now(),
            ]);
        });
    }

    /**
     * Rakit tampilan notifikasi. Nama bahan/produk milik Catalog (tak ada di sini),
     * jadi body pakai jumlah + payload mentah disimpan untuk detail di UI nanti.
     *
     * @return array{0:string,1:string,2:string} severity, title, body
     */
    private function present(array $envelope): array
    {
        $payload = $envelope['payload'];

        return match ($envelope['event_type']) {
            'inventory.low_stock' => [
                'warning',
                'Stok menipis',
                sprintf('%d bahan menyentuh batas minimum setelah penjualan. Saatnya belanja sebelum habis.', count($payload['items'])),
            ],
            'inventory.shortfall' => [
                'critical',
                'Stok jebol (minus)',
                sprintf('%d bahan saldonya minus setelah penjualan. Segera opname atau restock.', count($payload['items'])),
            ],
            'inventory.recipe_missing' => [
                'info',
                'Resep belum lengkap',
                sprintf('%d produk terjual belum punya resep; stoknya tidak terpotong. Lengkapi resep di menu.', count($payload['product_ids'])),
            ],
            // Nominalnya ikut ditulis. Owner yang cuma membaca "pesanan
            // dibatalkan" tak bisa membedakan segelas kopi salah pesan dari
            // rombongan dua juta yang batal — dan itu satu-satunya alasan ia
            // perlu diberi tahu sama sekali. Alasan ikut kalau kasir menulisnya.
            'order.cancelled' => [
                'warning',
                'Pesanan dibatalkan',
                sprintf(
                    'Pesanan %s senilai Rp %s dibatalkan kasir.%s',
                    $payload['order_number'],
                    number_format((int) $payload['grand_total'], 0, ',', '.'),
                    isset($payload['reason']) && $payload['reason'] !== null
                        ? ' Alasan: '.$payload['reason']
                        : ' Tanpa alasan tertulis.',
                ),
            ],
        };
    }

    private function validEnvelope(array $envelope): bool
    {
        $type = $envelope['event_type'] ?? null;
        if (! is_string($type) || ! array_key_exists($type, self::TYPES)
            || ! $this->uuid($envelope['event_id'] ?? null)
            || ! $this->uuid($envelope['tenant_id'] ?? null)
            || ! $this->uuid($envelope['outlet_id'] ?? null)
            || ! $this->date($envelope['occurred_at'] ?? null)) {
            return false;
        }

        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload) || ! $this->nonEmptyString($payload['order_id'] ?? null)) {
            return false;
        }

        if ($type === 'order.cancelled') {
            // TIPE dan RENTANG, bukan sekadar "ada". Nominal non-numerik
            // meledak di number_format dan menjatuhkan daemon; nominal negatif
            // tercetak sebagai "Rp -50.000" di inbox owner. Dua-duanya lolos
            // kalau yang diperiksa cuma keberadaannya.
            $total = $payload['grand_total'] ?? null;

            return $this->nonEmptyString($payload['order_number'] ?? null)
                && is_int($total) && $total >= 0;
        }

        if ($type === 'inventory.recipe_missing') {
            $ids = $payload['product_ids'] ?? null;
            if (! is_array($ids) || $ids === []) {
                return false;
            }
            foreach ($ids as $id) {
                if (! $this->nonEmptyString($id)) {
                    return false;
                }
            }

            return true;
        }

        // low_stock & shortfall: butuh items[] dengan ingredient_id
        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (! is_array($item) || ! $this->nonEmptyString($item['ingredient_id'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
