<?php

namespace App\Console\Commands;

use App\Models\Table;
use Illuminate\Console\Command;

/**
 * Cetak alamat pelanggan untuk tiap meja.
 *
 * Ada sebabnya ini jadi perintah sendiri, bukan potongan tinker yang disalin
 * tiap kali: `qr_token` disimpan telanjang di database, sementara yang bisa
 * dibuka orang adalah alamat lengkap. Menyusunnya manual berarti ada
 * kesempatan salah tempel setiap kali — dan alamat yang keliru tak menampilkan
 * pesan "alamat salah", melainkan "scan QR di meja", yang menyesatkan ke arah
 * yang sama sekali lain.
 *
 * Kolom outlet juga disengaja: satu database pengembangan gampang memuat meja
 * dari beberapa outlet, dan meja outlet lain TERBUKA seperti biasa — cuma
 * menunya kosong dan pesanannya tak pernah muncul di layar kasir yang sedang
 * dibuka. Kolom inilah yang membuat kekeliruan itu terlihat sejak awal.
 */
class DaftarMeja extends Command
{
    protected $signature = 'meja:daftar
        {--base=http://localhost:5173 : Alamat app pelanggan}
        {--outlet= : Batasi ke satu outlet}
        {--semua : Ikutkan meja nonaktif}';

    protected $description = 'Cetak alamat pelanggan untuk tiap meja';

    public function handle(): int
    {
        $base = rtrim((string) $this->option('base'), '/');

        $meja = Table::query()
            ->when(! $this->option('semua'), fn ($q) => $q->where('is_active', true))
            ->when($this->option('outlet'), fn ($q, $outlet) => $q->where('outlet_id', $outlet))
            ->orderBy('outlet_id')
            ->orderBy('label')
            ->get(['label', 'qr_token', 'outlet_id', 'is_active']);

        if ($meja->isEmpty()) {
            $this->warn('Belum ada meja. Buat dulu lewat POST /api/tables sebagai owner.');

            return self::SUCCESS;
        }

        $this->table(
            ['Meja', 'Outlet', 'Aktif', 'Alamat pelanggan'],
            $meja->map(fn (Table $t) => [
                $t->label,
                // Dipendekkan supaya tabelnya tetap terbaca; yang dibutuhkan
                // cuma membedakan satu outlet dari yang lain, bukan menyalinnya.
                substr((string) $t->outlet_id, 0, 8).'…',
                $t->is_active ? 'ya' : 'TIDAK',
                $base.'/t/'.$t->qr_token,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
