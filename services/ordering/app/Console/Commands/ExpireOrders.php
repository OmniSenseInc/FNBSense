<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Langkah 9: tandai order PENDING yang lewat expires_at menjadi EXPIRED.
 *
 * Expire BUKAN jalur uang -> TIDAK menulis outbox. Query sengaja hanya
 * menyentuh status='pending': PAID (terminal), CANCELLED, dan EXPIRED tak
 * boleh tersenggol. Idempoten alami: run berikutnya tak menemukan baris lagi.
 */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Tandai order PENDING yang sudah melewati expires_at menjadi EXPIRED';

    public function handle(): int
    {
        $affected = Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['status' => OrderStatus::Expired->value]);

        $this->info("orders:expire selesai — {$affected} order kedaluwarsa.");

        return self::SUCCESS;
    }
}
