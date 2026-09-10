<?php

declare(strict_types=1);

namespace App\Messaging;

use RuntimeException;

/**
 * Sinyal dedup yang JUJUR: order ini sudah ditandai processed oleh consumer
 * lain (bentrokan unique(order_id) di processed_orders), bukan galat DB.
 * Dibedakan dari QueryException supaya ACK hanya terjadi untuk duplikat
 * sungguhan — bentrokan unique di tabel lain (stock_balances) tetap naik
 * sebagai QueryException → Requeue, dan potongan stok tidak pernah hilang.
 */
final class DuplicateProcessedOrderException extends RuntimeException {}
