<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar saat Catalog tak bisa dihubungi / balas non-2xx saat Ordering butuh
 * harga produk. Sengaja kelas sendiri supaya langkah pembuatan order bisa
 * menangkapnya spesifik dan menerjemahkannya jadi 503 ("menu sedang tak bisa
 * diakses") — bukan 500 yang membocorkan bahwa dependensi internal tumbang.
 *
 * Order baru gagal saat Catalog down = perilaku yang DIINGINKAN (blueprint):
 * lebih baik menolak order daripada menebak harga.
 */
class CatalogUnavailableException extends RuntimeException
{
}
