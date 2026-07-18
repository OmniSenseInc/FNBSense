<?php

namespace App\Enums;

/**
 * Status order. PAID itu terminal & absolut — tak ada transisi keluar darinya.
 * Transisi sah hanya dari Pending (lihat state machine di docs/ORDERING.md).
 */
enum OrderStatus: string
{
    case Pending = 'pending';       // baru dibuat, menunggu bayar di kasir
    case Paid = 'paid';             // sudah dibayar — terminal, uang masuk
    case Cancelled = 'cancelled';   // dibatalkan kasir
    case Expired = 'expired';       // kedaluwarsa (scheduler), tak jadi dibayar
}
