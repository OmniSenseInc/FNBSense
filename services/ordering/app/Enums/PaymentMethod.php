<?php

namespace App\Enums;

/**
 * Cara bayar yang dicatat kasir saat konfirmasi PAID.
 * QRIS statis & cash sekarang; webhook QRIS dinamis nanti tanpa ubah enum ini.
 */
enum PaymentMethod: string
{
    case QrisStatic = 'qris_static';
    case Cash = 'cash';
}
