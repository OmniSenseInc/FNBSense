<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';       // pemilik/pengelola — akses penuh
    case Cashier = 'cashier';   // kasir — verifikasi bayar + lihat pesanan dapur
}
