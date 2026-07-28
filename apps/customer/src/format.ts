/**
 * Data mentah -> teks yang dibaca pelanggan.
 *
 * Isinya fungsi murni saja, nol komponen: file yang mencampur keduanya
 * mematikan Fast Refresh saat dev, dan fungsi seperti ini justru yang paling
 * enak diuji tanpa merender apa pun.
 */

/**
 * Uang di sistem ini integer rupiah, bukan pecahan — jadi nol desimal.
 *
 * Dipusatkan setelah muncul salinan ketiga (menu, status, dialog produk).
 * Angka yang tampil beda format di layar berbeda membuat pelanggan mengira
 * angkanya sendiri yang berbeda.
 */
export function rupiah(nilai: number): string {
  return 'Rp ' + new Intl.NumberFormat('id-ID').format(nilai)
}

/**
 * Bunyi tombol utama di lembar detail menu.
 *
 * Tiga cabangnya gampang tertukar, dan kalau tertukar pelanggan menekan
 * "Tambah" padahal yang terjadi menghapus pesanannya.
 */
export function labelTombol(draft: number, qtySekarang: number): string {
  if (draft === 0) return 'Hapus dari keranjang'
  if (qtySekarang > 0) return 'Perbarui keranjang'
  return 'Tambah ke keranjang'
}
