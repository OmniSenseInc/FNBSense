import { describe, expect, it } from 'vitest'
import type { Pesanan } from './api'
import { kelompokkanPerPembatal, urutkanTerbaru } from './pembatalan'

function pesanan(ubah: Partial<Pesanan> = {}): Pesanan {
  return {
    id: 'ord-1',
    order_number: 'A-001',
    customer_name: '',
    grand_total: 45_000,
    subtotal: 45_000,
    layanan: 0,
    pajak: 0,
    caraBayar: null,
    niatBayar: null,
    meja: null,
    tipe: null,
    waktuBayar: null,
    klaimBayar: null,
    siapPada: null,
    created_at: null,
    alasan: null,
    pembatal: null,
    diubahPada: null,
    expires_at: null,
    items: [],
    ...ubah,
  }
}

describe('urutkanTerbaru', () => {
  it('menaruh yang paling baru dibatalkan di atas', () => {
    const hasil = urutkanTerbaru([
      pesanan({ id: 'lama', diubahPada: '2026-08-15T10:00:00Z' }),
      pesanan({ id: 'baru', diubahPada: '2026-08-16T03:00:00Z' }),
      pesanan({ id: 'tengah', diubahPada: '2026-08-15T20:00:00Z' }),
    ])

    expect(hasil.map((p) => p.id)).toEqual(['baru', 'tengah', 'lama'])
  })

  it('menyalin, bukan mengubah urutan masukan di tempat', () => {
    const asli = [
      pesanan({ id: 'a', diubahPada: '2026-08-15T00:00:00Z' }),
      pesanan({ id: 'b', diubahPada: '2026-08-16T00:00:00Z' }),
    ]

    urutkanTerbaru(asli)

    // Input tak tersentuh: komponen lain yang memegang daftar yang sama tak
    // boleh ikut berubah urutannya diam-diam.
    expect(asli.map((p) => p.id)).toEqual(['a', 'b'])
  })
})

describe('kelompokkanPerPembatal', () => {
  const nama = new Map([
    ['1', 'Omnikasir'],
    ['2', 'Owner Uji'],
  ])

  it('menjumlahkan jumlah & nominal per pembatal, nominal terbesar di atas', () => {
    const hasil = kelompokkanPerPembatal(
      [
        pesanan({ pembatal: '1', grand_total: 20_000 }),
        pesanan({ pembatal: '1', grand_total: 30_000 }),
        pesanan({ pembatal: '2', grand_total: 100_000 }),
      ],
      nama,
    )

    expect(hasil).toHaveLength(2)
    expect(hasil[0]).toMatchObject({ nama: 'Owner Uji', jumlah: 1, nominal: 100_000 })
    expect(hasil[1]).toMatchObject({ nama: 'Omnikasir', jumlah: 2, nominal: 50_000 })
  })

  it('pembatal yang tak ada di daftar staf jadi "Kasir (dihapus)"', () => {
    const hasil = kelompokkanPerPembatal([pesanan({ pembatal: '99', grand_total: 5_000 })], nama)

    expect(hasil[0].nama).toBe('Kasir (dihapus)')
  })

  it('nominal yang rusak (NaN) tidak ikut dijumlah', () => {
    const hasil = kelompokkanPerPembatal(
      [pesanan({ pembatal: '1', grand_total: NaN }), pesanan({ pembatal: '1', grand_total: 10_000 })],
      nama,
    )

    expect(hasil[0].nominal).toBe(10_000)
  })

  it('dua pembatal berbeda yang sama-sama tak dikenal jadi dua baris terpisah', () => {
    // Dua kasir yang sama-sama sudah dihapus -> dua-duanya "Kasir (dihapus)".
    // id-nya harus tetap beda, supaya React tak mengira keduanya satu elemen.
    const hasil = kelompokkanPerPembatal(
      [pesanan({ pembatal: '99', grand_total: 5_000 }), pesanan({ pembatal: '100', grand_total: 7_000 })],
      nama,
    )

    expect(hasil).toHaveLength(2)
    expect(hasil.map((h) => h.id).sort()).toEqual(['100', '99'])
    expect(hasil.every((h) => h.nama === 'Kasir (dihapus)')).toBe(true)
  })
})
