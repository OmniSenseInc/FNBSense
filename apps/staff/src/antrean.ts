/**
 * Aturan antrean yang berlaku ANTAR-pesanan, bukan di dalam satu pesanan.
 *
 * Tinggal di luar komponen supaya bisa diuji: selama ia hidup di dalam JSX,
 * satu-satunya cara memeriksanya adalah merender layar (pola yang sama seperti
 * `keranjang.ts` di app pelanggan).
 */

import { type Pesanan } from './api'

/**
 * Total yang muncul lebih dari sekali di antrean yang sedang ditampilkan.
 *
 * Ini pagar khas QRIS statis. Nominal tidak terkunci di dalam QR, jadi satu-
 * satunya petunjuk kasir untuk mencocokkan notifikasi mutasi ke pesanan adalah
 * angkanya. Begitu dua meja punya total identik — dua-duanya kopi + roti —
 * notifikasi "Rp 45.000" tak lagi menunjuk siapa pun, dan bisa saja hanya SATU
 * yang benar-benar terkirim. Bahayanya bukan karena sering terjadi, tapi karena
 * kasir tak punya cara menyadari dia salah orang.
 *
 * Dihitung dari daftar yang sudah dimuat — nol permintaan tambahan ke server.
 */
export function totalKembar(daftar: Pesanan[]): Set<number> {
  const jumlahPer = new Map<number, number>()

  for (const pesanan of daftar) {
    // Total rusak dilewati. Map menyamakan NaN dengan NaN, jadi tanpa penjaga
    // ini dua pesanan yang totalnya sama-sama TAK DIKETAHUI akan saling
    // dituduh kembar — peringatan yang dikarang dari ketiadaan data. Kartunya
    // sendiri sudah beralarm lewat "Rp —" (lihat rupiah()).
    if (!Number.isFinite(pesanan.grand_total)) continue

    jumlahPer.set(pesanan.grand_total, (jumlahPer.get(pesanan.grand_total) ?? 0) + 1)
  }

  const kembar = new Set<number>()
  for (const [total, jumlah] of jumlahPer) {
    if (jumlah > 1) kembar.add(total)
  }

  return kembar
}
