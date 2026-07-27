import { describe, expect, it } from 'vitest'
import { MAKS_QTY, susunPesanan } from './api'

const DASAR = { qrToken: 'qr-abc', nama: 'Vincent' }

describe('susunPesanan', () => {
  it('menyusun payload sesuai kontrak StoreOrderRequest', () => {
    expect(susunPesanan({ ...DASAR, qty: { 'prod-1': 2 } })).toEqual({
      qr_token: 'qr-abc',
      order_type: 'dine_in',
      customer_name: 'Vincent',
      items: [{ product_id: 'prod-1', qty: 2 }],
    })
  })

  it('membuang item ber-qty 0 — pelanggan menaikkan lalu menurunkan lagi', () => {
    const hasil = susunPesanan({ ...DASAR, qty: { 'prod-1': 0, 'prod-2': 3 } })

    expect(hasil.items).toEqual([{ product_id: 'prod-2', qty: 3 }])
  })

  it('menjepit qty di batas server, bukan membiarkannya ditolak 422', () => {
    const hasil = susunPesanan({ ...DASAR, qty: { 'prod-1': 500 } })

    expect(hasil.items[0].qty).toBe(MAKS_QTY)
  })

  it('membuang qty negatif, bukan mengirimnya sebagai pengurang', () => {
    const hasil = susunPesanan({ ...DASAR, qty: { 'prod-1': -2, 'prod-2': 1 } })

    expect(hasil.items).toEqual([{ product_id: 'prod-2', qty: 1 }])
  })

  it('membulatkan qty pecahan ke bawah — server hanya menerima integer', () => {
    const hasil = susunPesanan({ ...DASAR, qty: { 'prod-1': 2.9 } })

    expect(hasil.items[0].qty).toBe(2)
  })

  it('merapikan spasi di nama pelanggan', () => {
    const hasil = susunPesanan({ ...DASAR, nama: '  Vincent  ', qty: { 'prod-1': 1 } })

    expect(hasil.customer_name).toBe('Vincent')
  })

  it('keranjang kosong menghasilkan items kosong, bukan meledak', () => {
    // UI yang mencegah pengiriman; fungsi ini tetap harus jujur, tak menebak.
    expect(susunPesanan({ ...DASAR, qty: {} }).items).toEqual([])
  })

  it('TIDAK diam-diam memotong keranjang di atas batas 50 item', () => {
    // Memotong = pelanggan membayar pesanan yang bukan miliknya. Biar UI yang
    // menolak dengan pesan jelas; fungsi ini tak boleh mengarang.
    const qty = Object.fromEntries(
      Array.from({ length: 60 }, (_, i) => [`prod-${i}`, 1]),
    )

    expect(susunPesanan({ ...DASAR, qty }).items).toHaveLength(60)
  })
})
