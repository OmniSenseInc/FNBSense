import { describe, expect, it } from 'vitest'
import { riwayatHariIni, totalKembar } from './antrean'
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
    meja: null,
    tipe: null,
    waktuBayar: null,
    created_at: null,
    expires_at: null,
    items: [],
  }
}

describe('riwayatHariIni', () => {
  const sekarang = Date.parse('2026-07-31T20:00:00+07:00')

  /** Pesanan lunas dengan waktu konfirmasi tertentu. */
  function lunas(id: string, waktuBayar: string | null): Pesanan {
    return { ...pesanan(id, 20_000), waktuBayar }
  }

  it('menyimpan pesanan yang dibayar hari ini', () => {
    const hasil = riwayatHariIni([lunas('a', '2026-07-31T09:15:00+07:00')], sekarang)

    expect(hasil.map((p) => p.id)).toEqual(['a'])
  })

  it('membuang pesanan kemarin walau baru beberapa jam lalu', () => {
    // 23:30 kemarin ke 20:00 hari ini cuma terpaut 20 jam. Kalau penyaringnya
    // memakai selisih 24 jam, pesanan ini ikut — padahal "hari ini" bagi kasir
    // berakhir di tengah malam, bukan sehari setelah layar dibuka.
    const hasil = riwayatHariIni([lunas('kemarin', '2026-07-30T23:30:00+07:00')], sekarang)

    expect(hasil).toEqual([])
  })

  it('menaruh yang paling baru dibayar di pucuk', () => {
    // Orang yang kembali minta cetak ulang baru saja pergi dari depan kasir.
    const hasil = riwayatHariIni(
      [
        lunas('pagi', '2026-07-31T08:00:00+07:00'),
        lunas('sore', '2026-07-31T17:00:00+07:00'),
        lunas('siang', '2026-07-31T12:00:00+07:00'),
      ],
      sekarang,
    )

    expect(hasil.map((p) => p.id)).toEqual(['sore', 'siang', 'pagi'])
  })

  it('membuang pesanan yang tak punya waktu bayar', () => {
    expect(riwayatHariIni([lunas('belum', null)], sekarang)).toEqual([])
  })

  it('membuang waktu bayar yang tak terbaca, bukan menaruhnya di ujung', () => {
    // Date.parse() menghasilkan NaN, dan NaN di dalam sort() menempatkan baris
    // itu di posisi acak — nota yang salah dibuka lebih buruk dari nota hilang.
    expect(riwayatHariIni([lunas('rusak', 'kemarin sore')], sekarang)).toEqual([])
  })
})

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
