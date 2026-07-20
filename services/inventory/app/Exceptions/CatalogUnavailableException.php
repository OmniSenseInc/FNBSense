<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Catalog tak dapat dihubungi / membalas tak waras saat consumer minta resep.
 *
 * KONTRAK F4b (#8): consumer yang menangkap ini WAJIB nack(requeue) & TIDAK
 * menandai order processed — potong stok tertunda, bukan hilang. Bedakan dari
 * "resep kosong" (Catalog menjawab tapi produk tak punya resep) yang justru
 * di-skip + ACK (#9).
 */
class CatalogUnavailableException extends RuntimeException
{
}
