/**
 * Data mentah -> teks yang dibaca kasir. Fungsi murni saja, nol komponen.
 *
 * Sengaja disalin dari apps/customer, bukan dibagi lewat paket bersama: dua
 * fungsi kecil tak cukup mahal untuk membayar ongkos workspace/monorepo.
 * Kalau salinannya sudah mencapai lima, barulah angkat jadi paket.
 */

/** Uang di sistem ini integer rupiah, bukan pecahan — jadi nol desimal. */
export function rupiah(nilai: number): string {
  // Total yang rusak (lihat petakanPesanan) tak boleh tampil sebagai angka apa
  // pun; kasir harus melihat bahwa nilainya tidak diketahui.
  if (!Number.isFinite(nilai)) return 'Rp —'
  return 'Rp ' + new Intl.NumberFormat('id-ID').format(nilai)
}

/**
 * Sisa waktu sampai pesanan kedaluwarsa, dibulatkan KE ATAS ke menit.
 *
 * Menit, bukan detik: angka yang berdetak tiap detik menarik mata kasir ke
 * layar padahal keputusannya tak berubah sedetik sekali. Pembulatan ke atas
 * membuat batasnya jujur — selama masih tersisa waktu berapa pun hasilnya
 * minimal 1, jadi 0 dan negatif berarti benar-benar sudah lewat.
 *
 * `sekarang` disuntik supaya fungsi ini bisa diuji tanpa membekukan jam sistem.
 *
 * ponytail: dibandingkan dengan jam PERANGKAT KASIR, bukan jam server. Tablet
 * yang jamnya melenceng menampilkan sisa waktu yang melenceng sebesar itu juga.
 * Diterima karena server tetap pemutus sebenarnya (`orders:expire` dan 409 saat
 * konfirmasi), jadi yang salah cuma tampilannya. Kalau kelak menggigit, kirim
 * `server_time` di respons antrean dan hitung selisihnya sekali di `panggil()`.
 */
export function sisaMenit(iso: string | null, sekarang: number = Date.now()): number | null {
  if (!iso) return null
  const batas = new Date(iso).getTime()
  // Tanggal tak terbaca -> null, BUKAN 0. Nol akan tampil sebagai "lewat batas"
  // dan mendorong kasir menyimpulkan sesuatu dari data yang rusak.
  if (Number.isNaN(batas)) return null
  return Math.ceil((batas - sekarang) / 60_000)
}

/**
 * Jam pesanan masuk, "14:05". Kasir memakainya untuk memutuskan sendiri siapa
 * yang lebih dulu menunggu saat beberapa orang berdiri bersamaan.
 */
export function jam(iso: string | null): string {
  if (!iso) return '—'
  const waktu = new Date(iso)
  if (Number.isNaN(waktu.getTime())) return '—'
  return new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(waktu)
}
