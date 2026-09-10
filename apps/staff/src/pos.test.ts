import { describe, expect, it } from 'vitest'
import type { ProdukMenu } from './api'
import {
  hapusKeranjang,
  jumlahItem,
  kurangKeranjang,
  naikkanQty,
  tambahKeranjang,
  totalKeranjang,
  type BarisKeranjang,
} from './pos'

function produk(id: string, harga: number): ProdukMenu {
  return { id, nama: `Produk ${id}`, harga, habis: false }
}

describe('keranjang POS', () => {
  it('tambah produk baru jadi satu baris qty 1', () => {
    expect(tambahKeranjang([], produk('a', 10000))).toEqual([
      { produkId: 'a', nama: 'Produk a', harga: 10000, qty: 1 },
    ])
  })

  it('tambah produk yang sama menaikkan qty, bukan baris baru', () => {
    const hasil = tambahKeranjang(
      [{ produkId: 'a', nama: 'Produk a', harga: 10000, qty: 2 }],
      produk('a', 10000),
    )

    expect(hasil).toHaveLength(1)
    expect(hasil[0].qty).toBe(3)
  })

  it('kurangi sampai qty 0 berarti baris dibuang', () => {
    const hasil = kurangKeranjang([{ produkId: 'a', nama: 'A', harga: 10000, qty: 1 }], 'a')

    expect(hasil).toHaveLength(0)
  })

  it('naikkanQty menambah tepat satu', () => {
    const hasil = naikkanQty([{ produkId: 'a', nama: 'A', harga: 10000, qty: 2 }], 'a')

    expect(hasil[0].qty).toBe(3)
  })

  it('hapus baris seluruhnya', () => {
    const hasil = hapusKeranjang([{ produkId: 'a', nama: 'A', harga: 10000, qty: 1 }], 'a')

    expect(hasil).toHaveLength(0)
  })

  it('total = jumlah harga x qty; jumlahItem = satuan', () => {
    const keranjang: BarisKeranjang[] = [
      { produkId: 'a', nama: 'A', harga: 10000, qty: 2 },
      { produkId: 'b', nama: 'B', harga: 25000, qty: 1 },
    ]

    expect(totalKeranjang(keranjang)).toBe(45000)
    expect(jumlahItem(keranjang)).toBe(3)
  })

  it('keranjang kosong: total dan jumlah nol', () => {
    expect(totalKeranjang([])).toBe(0)
    expect(jumlahItem([])).toBe(0)
  })
})
