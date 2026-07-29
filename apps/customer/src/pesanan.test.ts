import { describe, expect, it } from 'vitest'
import { MAKS_NOTE, MAKS_QTY, susunPesanan } from './api'

const DASAR = { qrToken: 'qr-abc', nama: 'Vincent' }

/** Baris keranjang ringkas — catatan kosong adalah keadaan biasa, bukan kasus khusus. */
const baris = (qty: number, note = '') => ({ qty, note })

describe('susunPesanan', () => {
  it('menyusun payload sesuai kontrak StoreOrderRequest', () => {
    expect(susunPesanan({ ...DASAR, isi: { 'prod-1': baris(2) } })).toEqual({
      qr_token: 'qr-abc',
      order_type: 'dine_in',
      customer_name: 'Vincent',
      items: [{ product_id: 'prod-1', qty: 2 }],
    })
  })

  it('membuang item ber-qty 0 — pelanggan menaikkan lalu menurunkan lagi', () => {
    const hasil = susunPesanan({
      ...DASAR,
      isi: { 'prod-1': baris(0), 'prod-2': baris(3) },
    })

    expect(hasil.items).toEqual([{ product_id: 'prod-2', qty: 3 }])
  })

  it('menjepit qty di batas server, bukan membiarkannya ditolak 422', () => {
    const hasil = susunPesanan({ ...DASAR, isi: { 'prod-1': baris(500) } })

    expect(hasil.items[0].qty).toBe(MAKS_QTY)
  })

  it('membuang qty negatif, bukan mengirimnya sebagai pengurang', () => {
    const hasil = susunPesanan({
      ...DASAR,
      isi: { 'prod-1': baris(-2), 'prod-2': baris(1) },
    })

    expect(hasil.items).toEqual([{ product_id: 'prod-2', qty: 1 }])
  })

  it('membulatkan qty pecahan ke bawah — server hanya menerima integer', () => {
    const hasil = susunPesanan({ ...DASAR, isi: { 'prod-1': baris(2.9) } })

    expect(hasil.items[0].qty).toBe(2)
  })

  it('merapikan spasi di nama pelanggan', () => {
    const hasil = susunPesanan({ ...DASAR, nama: '  Vincent  ', isi: { 'prod-1': baris(1) } })

    expect(hasil.customer_name).toBe('Vincent')
  })

  it('keranjang kosong menghasilkan items kosong, bukan meledak', () => {
    // UI yang mencegah pengiriman; fungsi ini tetap harus jujur, tak menebak.
    expect(susunPesanan({ ...DASAR, isi: {} }).items).toEqual([])
  })

  it('TIDAK diam-diam memotong keranjang di atas batas 50 item', () => {
    // Memotong = pelanggan membayar pesanan yang bukan miliknya. Biar UI yang
    // menolak dengan pesan jelas; fungsi ini tak boleh mengarang.
    const isi = Object.fromEntries(
      Array.from({ length: 60 }, (_, i) => [`prod-${i}`, baris(1)]),
    )

    expect(susunPesanan({ ...DASAR, isi }).items).toHaveLength(60)
  })
})

describe('catatan per item', () => {
  it('ikut terkirim bersama itemnya', () => {
    const hasil = susunPesanan({
      ...DASAR,
      isi: { 'prod-1': baris(2, 'tanpa gula') },
    })

    expect(hasil.items).toEqual([{ product_id: 'prod-1', qty: 2, note: 'tanpa gula' }])
  })

  it('menempel ke item yang BENAR saat cuma sebagian bercatatan', () => {
    // Inilah kegagalan yang paling mahal dan paling tak kelihatan: "tanpa gula"
    // nyasar ke item lain berarti dapur membuat minuman yang salah.
    const hasil = susunPesanan({
      ...DASAR,
      isi: {
        'prod-kopi': baris(1, 'tanpa gula'),
        'prod-teh': baris(2),
        'prod-roti': baris(1, 'dipanggang'),
      },
    })

    expect(hasil.items).toEqual([
      { product_id: 'prod-kopi', qty: 1, note: 'tanpa gula' },
      { product_id: 'prod-teh', qty: 2 },
      { product_id: 'prod-roti', qty: 1, note: 'dipanggang' },
    ])
  })

  it('catatan kosong TIDAK dikirim sebagai "" — kolomnya nullable', () => {
    const hasil = susunPesanan({ ...DASAR, isi: { 'prod-1': baris(1, '') } })

    expect(hasil.items[0]).not.toHaveProperty('note')
  })

  it('catatan berisi spasi doang dianggap kosong, bukan catatan sungguhan', () => {
    // Kasir tak boleh melihat baris catatan berisi udara di layar antreannya.
    const hasil = susunPesanan({ ...DASAR, isi: { 'prod-1': baris(1, '   \n  ') } })

    expect(hasil.items[0]).not.toHaveProperty('note')
  })

  it('merapikan spasi di ujung catatan', () => {
    const hasil = susunPesanan({ ...DASAR, isi: { 'prod-1': baris(1, '  tanpa gula  ') } })

    expect(hasil.items[0].note).toBe('tanpa gula')
  })

  it('memotong catatan di batas server, bukan membiarkannya ditolak 422', () => {
    // maxLength di textarea tak cukup: isi keranjang datang dari localStorage
    // yang bisa diedit lewat devtools atau sisa versi app yang lama.
    const hasil = susunPesanan({ ...DASAR, isi: { 'prod-1': baris(1, 'a'.repeat(400)) } })

    expect(hasil.items[0].note).toHaveLength(MAKS_NOTE)
  })

  it('catatan pada item ber-qty 0 ikut hilang, tak menempel ke pesanan', () => {
    const hasil = susunPesanan({
      ...DASAR,
      isi: { 'prod-1': baris(0, 'tanpa gula'), 'prod-2': baris(1) },
    })

    expect(hasil.items).toEqual([{ product_id: 'prod-2', qty: 1 }])
  })
})
