<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';       // pemilik/pengelola — akses penuh
    case Manager = 'manager';   // pengawas — baca laporan/menu/stok, tanpa ubah
    case Cashier = 'cashier';   // kasir — verifikasi bayar + lihat pesanan dapur
}
