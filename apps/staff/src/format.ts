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
