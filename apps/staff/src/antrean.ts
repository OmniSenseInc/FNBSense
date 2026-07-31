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
/**
 * Pesanan yang dibayar pada hari yang sama dengan `sekarang`, terbaru dulu.
 *
 * Kasir membuka riwayat untuk satu alasan: seseorang kembali dan minta notanya
 * dicetak ulang. Orang itu baru saja pergi, jadi yang dicari selalu ada di
 * pucuk — karena itu urutannya terbalik dari antrean, yang justru mendahulukan
 * yang paling lama menunggu.
 *
 * Server SUDAH memangkas lewat `paid_since` (lihat `ambilRiwayat()`), jadi yang
 * tiba di sini tinggal sehari terakhir. Penyaringan ulang di layar tetap ada
 * dan bukan mubazir: batas server dihitung dari tengah malam menurut jam
 * PERANGKAT ini, sedangkan yang menentukan benar-salahnya "hari ini" bagi kasir
 * juga jam perangkat itu. Lapisan ini yang menjaga keduanya tak pernah berselisih
 * seandainya zona waktu server berbeda.
 */
export function riwayatHariIni(daftar: Pesanan[], sekarang: number = Date.now()): Pesanan[] {
  const acuan = new Date(sekarang)

  return daftar
    .filter((pesanan) => {
      // Belum sempat dikonfirmasi -> bukan riwayat. Ini juga yang menjaga
      // layar tetap benar seandainya server kelak mengirim status lain.
      if (!pesanan.waktuBayar) return false

      const waktu = new Date(pesanan.waktuBayar)
      if (Number.isNaN(waktu.getTime())) return false

      // Dibandingkan per komponen tanggal LOKAL, bukan lewat selisih 24 jam:
      // "hari ini" bagi kasir berakhir di tengah malam, bukan 24 jam setelah
      // layar dibuka.
      return (
        waktu.getFullYear() === acuan.getFullYear() &&
        waktu.getMonth() === acuan.getMonth() &&
        waktu.getDate() === acuan.getDate()
      )
    })
    .sort((a, b) => Date.parse(b.waktuBayar ?? '') - Date.parse(a.waktuBayar ?? ''))
}

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
