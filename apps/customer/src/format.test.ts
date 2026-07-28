import { describe, expect, it } from 'vitest'
import { labelTombol } from './format'

/**
 * Tombol ini satu-satunya yang mengubah keranjang dari dalam lembar detail.
 * Kalau labelnya tidak cocok dengan yang benar-benar terjadi, pelanggan
 * menekan "Tambah" lalu pesanannya justru hilang — dan dia baru sadar di
 * kasir.
 */
describe('labelTombol', () => {
  it('menawarkan menambah untuk menu yang belum ada di keranjang', () => {
    expect(labelTombol(2, 0)).toBe('Tambah ke keranjang')
  })

  it('menawarkan memperbarui, bukan menambah, kalau menunya sudah dipesan', () => {
    // "Tambah" di sini bohong: angkanya DIGANTI, bukan dijumlahkan, jadi
    // pelanggan yang sudah punya 2 lalu menekan "Tambah 3" akan mengira
    // dapat 5.
    expect(labelTombol(3, 2)).toBe('Perbarui keranjang')
  })

  it('tetap menyebut memperbarui walau jumlahnya tak berubah', () => {
    expect(labelTombol(2, 2)).toBe('Perbarui keranjang')
  })

  it.each([
    ['sudah ada di keranjang', 2],
    ['belum ada di keranjang', 0],
  ])('menyebut menghapus saat jumlah diturunkan ke nol (%s)', (_nama, sekarang) => {
    expect(labelTombol(0, sekarang)).toBe('Hapus dari keranjang')
  })
})
