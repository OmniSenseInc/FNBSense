<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Models\ProcessedOrder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Pagar idempotensi consumer order.paid berbasis unique(order_id) di
 * processed_orders. Dipisah dari OrderPaidConsumer supaya keputusan
 * "ACK karena dedup" vs "galat DB lain → Requeue" BISA DIBEDAKAN.
 *
 * Latar: sebelumnya handle() menangkap UniqueConstraintViolationException
 * secara buta dan meng-ACK. Padahal unique(outlet_id, ingredient_id) di
 * stock_balances bisa bentrok untuk dua order BERBEDA yang balapan membuat
 * baris saldo baru — ACK di situ = potongan stok hilang permanen, diam-diam.
 * Bentrokan stock_balances bukan tanda "sudah diproses"; bentrokan
 * processed_orders-lah pagarnya. Bedanya harus ditanya ke DB, bukan ditebak.
 *
 * Tidak final: test menggantikannya dengan turunan untuk mensimulasikan
 * bentrokan unique yang datang dari tabel LAIN (bukan pagar ini).
 */
class ProcessedOrderGate
{
    /**
     * Tandai order sebagai telah diproses. true = kita yang menang, false =
     * consumer lain sudah menandainya (pesan duplikat) → pemanggil wajib ACK.
     *
     * WAJIB dipanggil DI DALAM transaksi deduct() supaya insert ikut rollback
     * bersama potongan bila terjadi galat lain.
     *
     * @throws QueryException galat DB selain bentrokan pagar → requeue
     */
    public function claim(string $orderId, string $tenantId, string $outletId, string $status): bool
    {
        try {
            ProcessedOrder::create([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'status' => $status,
                'processed_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Pagar menutup: consumer lain sudah menandai order ini processed.
            return false;
        }
        // QueryException & lainnya SENGAJA dibiarkan naik: transaksi rollback,
        // handle() menangkap QueryException → Requeue. Tidak ada lagi
        // ACK-mengoibek-kegagalan.
    }
}
