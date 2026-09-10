import type { ProdukMenu } from './api'

/**
 * Logika murni keranjang POS — dipisah dari komponen supaya bisa diuji tanpa
 * merender apa pun, sepola antrean.ts dan pembatalan.ts.
 */

/** Satu baris di keranjang POS. */
export type BarisKeranjang = {
  produkId: string
  nama: string
  harga: number
  qty: number
}

/** Tambah satu produk (atau naikkan qty kalau sudah ada). */
export function tambahKeranjang(keranjang: BarisKeranjang[], produk: ProdukMenu): BarisKeranjang[] {
  const ada = keranjang.find((b) => b.produkId === produk.id)
  if (ada) {
    return keranjang.map((b) => (b.produkId === produk.id ? { ...b, qty: b.qty + 1 } : b))
  }
  return [...keranjang, { produkId: produk.id, nama: produk.nama, harga: produk.harga, qty: 1 }]
}

/** Kurangi satu; qty nol berarti baris dibuang. */
export function kurangKeranjang(keranjang: BarisKeranjang[], produkId: string): BarisKeranjang[] {
  return keranjang
    .map((b) => (b.produkId === produkId ? { ...b, qty: b.qty - 1 } : b))
    .filter((b) => b.qty > 0)
}

/** Naikkan qty satu baris yang sudah ada (tombol + di keranjang). */
export function naikkanQty(keranjang: BarisKeranjang[], produkId: string): BarisKeranjang[] {
  return keranjang.map((b) => (b.produkId === produkId ? { ...b, qty: b.qty + 1 } : b))
}

/** Hapus satu baris seluruhnya. */
export function hapusKeranjang(keranjang: BarisKeranjang[], produkId: string): BarisKeranjang[] {
  return keranjang.filter((b) => b.produkId !== produkId)
}

/** Total rupiah keranjang (belum pajak/servis — itu urusan server). */
export function totalKeranjang(keranjang: BarisKeranjang[]): number {
  return keranjang.reduce((t, b) => t + b.harga * b.qty, 0)
}

/** Jumlah item (satuan, bukan baris). */
export function jumlahItem(keranjang: BarisKeranjang[]): number {
  return keranjang.reduce((t, b) => t + b.qty, 0)
}
