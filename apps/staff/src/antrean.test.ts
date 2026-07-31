import { describe, expect, it } from 'vitest'
import { totalKembar } from './antrean'
import { type Pesanan } from './api'

/** Pesanan seadanya — yang diuji cuma totalnya. */
function pesanan(id: string, grandTotal: number): Pesanan {
  return {
    id,
    order_number: id,
    customer_name: '',
    grand_total: grandTotal,
    subtotal: grandTotal,
    layanan: 0,
    pajak: 0,
    caraBayar: null,
    niatBayar: null,
    waktuBayar: null,
    created_at: null,
    expires_at: null,
    items: [],
  }
}

describe('totalKembar', () => {
  it('menandai total yang dipakai dua pesanan sekaligus', () => {
    const hasil = totalKembar([pesanan('a', 45_000), pesanan('b', 45_000), pesanan('c', 22_000)])

    expect(hasil.has(45_000)).toBe(true)
  })

  it('tidak menandai total yang cuma dipakai satu pesanan', () => {
    // Peringatan yang muncul di mana-mana akan diabaikan di mana-mana.
    const hasil = totalKembar([pesanan('a', 45_000), pesanan('b', 45_000), pesanan('c', 22_000)])

    expect(hasil.has(22_000)).toBe(false)
  })

  it('total yang dipakai tiga pesanan tetap satu nilai', () => {
    const hasil = totalKembar([pesanan('a', 30_000), pesanan('b', 30_000), pesanan('c', 30_000)])

    expect([...hasil]).toEqual([30_000])
  })

  it('dua total yang TAK DIKETAHUI tidak saling dituduh kembar', () => {
    // NaN dianggap sama dengan NaN oleh Map/Set. Tanpa penjaga Number.isFinite,
    // dua pesanan yang totalnya gagal dibaca akan memicu peringatan "nominal
    // kembar" — kesimpulan yang dikarang dari data yang justru tak ada.
    const hasil = totalKembar([pesanan('a', NaN), pesanan('b', NaN)])

    expect(hasil.size).toBe(0)
  })

  it('antrean kosong tidak menandai apa pun', () => {
    expect(totalKembar([]).size).toBe(0)
  })
})
