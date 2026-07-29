import { describe, expect, it } from 'vitest'
import { bacaQris } from './api'

const ALAMAT = 'https://contoh.test/qris/outlet-a.png'

/**
 * Blok `payment` dari server diperlakukan sebagai data asing.
 *
 * Nilainya berakhir langsung di `<img src>` pada layar orang yang sedang
 * membayar, jadi yang salah bentuk harus berhenti di sini — bukan tampil
 * sebagai ikon gambar patah tepat saat pelanggan paling butuh yakin.
 */
describe('bacaQris', () => {
  it('mengambil alamat gambar dari blok payment', () => {
    expect(bacaQris({ qris_image_url: ALAMAT })).toBe(ALAMAT)
  })

  it('null saat server mengirim null — pesanan yang tak boleh menampilkan QR', () => {
    // Inilah jalur normal untuk pesanan sudah dibayar / batal / kedaluwarsa:
    // servernya yang memutuskan, layar tinggal menurut.
    expect(bacaQris({ qris_image_url: null })).toBeNull()
  })

  it('null saat blok payment belum ada sama sekali', () => {
    // Server versi lama. Halaman status tak boleh tumbang cuma karena
    // responsnya belum membawa blok baru.
    expect(bacaQris(undefined)).toBeNull()
  })

  it.each([
    ['string kosong', ''],
    ['spasi doang', '   '],
  ])('null untuk %s — src kosong memanggil balik alamat halaman ini sendiri', (_nama, buruk) => {
    expect(bacaQris({ qris_image_url: buruk })).toBeNull()
  })

  it.each([
    ['angka', 123],
    ['objek', { url: ALAMAT }],
    ['array', [ALAMAT]],
    ['boolean', true],
  ])('null untuk %s, bukan diteruskan mentah ke <img>', (_nama, buruk) => {
    expect(bacaQris({ qris_image_url: buruk })).toBeNull()
  })

  it.each([
    ['payment berupa string', 'https://contoh.test'],
    ['payment berupa angka', 7],
    ['payment null', null],
  ])('null saat %s, bukan melempar', (_nama, buruk) => {
    // Melempar di sini membuat SELURUH halaman status blank — pelanggan
    // kehilangan nomor pesanan dan rinciannya, jauh lebih rusak daripada
    // sekadar kehilangan QR.
    expect(() => bacaQris(buruk)).not.toThrow()
    expect(bacaQris(buruk)).toBeNull()
  })
})
