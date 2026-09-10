import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  bacaPesananSaya,
  buangPesananSelesai,
  catatPesanan,
  kosongkanPesananSaya,
  kunciPesanan,
} from './pesananSaya'

/**
 * localStorage tiruan. Proyek ini tak memakai jsdom (test-nya murni fungsi),
 * jadi disediakan sendiri — pola yang sama seperti keranjang.test.ts.
 */
let simpanan: Record<string, string> = {}

beforeEach(() => {
  simpanan = {}
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => simpanan[k] ?? null,
    setItem: (k: string, v: string) => {
      simpanan[k] = v
    },
    removeItem: (k: string) => {
      delete simpanan[k]
    },
  })
})

const KUNCI = kunciPesanan('meja-a')

/** 2 Jan 2026 14:32 waktu lokal — dipakai sebagai "sekarang" di semua test. */
const SEKARANG = new Date(2026, 0, 2, 14, 32).getTime()
const SEJAM_LALU = SEKARANG - 60 * 60 * 1000

describe('catatPesanan', () => {
  it('pesanan terbaru ada di pucuk', () => {
    catatPesanan(KUNCI, 'ord-lama', SEJAM_LALU)
    const daftar = catatPesanan(KUNCI, 'ord-baru', SEKARANG)

    expect(daftar.map((e) => e.id)).toEqual(['ord-baru', 'ord-lama'])
  })

  it('id yang sama tak tercatat dua kali', () => {
    catatPesanan(KUNCI, 'ord-a', SEJAM_LALU)
    const daftar = catatPesanan(KUNCI, 'ord-a', SEKARANG)

    expect(daftar).toHaveLength(1)
  })

  it('meja lain punya daftar sendiri', () => {
    catatPesanan(KUNCI, 'ord-a', SEKARANG)

    expect(bacaPesananSaya(kunciPesanan('meja-b'), SEKARANG)).toEqual([])
  })

  /**
   * Pesanannya sudah sampai di server. Kehilangan daftar di HP tak boleh
   * menumbangkan layar orang yang baru saja memesan — di Safari mode
   * penyamaran, setItem MELEMPAR.
   */
  it('localStorage yang melempar tidak menumbangkan pengiriman', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => null,
      setItem: () => {
        throw new Error('kuota penuh')
      },
    })

    expect(() => catatPesanan(KUNCI, 'ord-a', SEKARANG)).not.toThrow()
  })
})

describe('bacaPesananSaya', () => {
  it('pesanan kemarin dibuang walau umurnya belum 24 jam', () => {
    // 23:30 kemarin -> cuma terpaut 15 jam, tapi hari pelanggan sudah berganti.
    const kemarinMalam = new Date(2026, 0, 1, 23, 30).getTime()
    simpanan[KUNCI] = JSON.stringify([{ id: 'ord-kemarin', dibuatPada: kemarinMalam }])

    expect(bacaPesananSaya(KUNCI, SEKARANG)).toEqual([])
  })

  it('entri rusak dibuang satuan, yang sehat selamat', () => {
    simpanan[KUNCI] = JSON.stringify([
      { id: 'ord-sehat', dibuatPada: SEKARANG },
      { id: '', dibuatPada: SEKARANG },
      { id: 'ord-tanpa-waktu' },
      'bukan objek',
      null,
    ])

    expect(bacaPesananSaya(KUNCI, SEKARANG).map((e) => e.id)).toEqual(['ord-sehat'])
  })

  it('isi yang bukan array tidak menumbangkan halaman menu', () => {
    simpanan[KUNCI] = '{"id":"ord-a"}'

    expect(bacaPesananSaya(KUNCI, SEKARANG)).toEqual([])
  })

  it('JSON rusak dibaca sebagai daftar kosong', () => {
    simpanan[KUNCI] = 'bukan json'

    expect(bacaPesananSaya(KUNCI, SEKARANG)).toEqual([])
  })

  it('disimpan maksimal 10, yang terlama terbuang', () => {
    for (let i = 0; i < 12; i++) {
      catatPesanan(KUNCI, `ord-${i}`, SEKARANG - (12 - i) * 1000)
    }

    const daftar = bacaPesananSaya(KUNCI, SEKARANG)

    expect(daftar).toHaveLength(10)
    expect(daftar[0].id).toBe('ord-11')
    expect(daftar.map((e) => e.id)).not.toContain('ord-0')
  })
})

describe('kosongkanPesananSaya', () => {
  it('menghapus seluruh daftar', () => {
    catatPesanan(KUNCI, 'ord-a', SEKARANG)
    kosongkanPesananSaya(KUNCI)

    expect(bacaPesananSaya(KUNCI, SEKARANG)).toEqual([])
  })

  it('localStorage yang melempar tidak melempar', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => null,
      setItem: () => {
        throw new Error('kuota penuh')
      },
      removeItem: () => {
        throw new Error('kuota penuh')
      },
    })

    expect(() => kosongkanPesananSaya(KUNCI)).not.toThrow()
  })
})

describe('buangPesananSelesai', () => {
  it('membuang hanya id yang disebut, sisanya selamat', () => {
    catatPesanan(KUNCI, 'ord-selesai', SEJAM_LALU)
    catatPesanan(KUNCI, 'ord-aktif', SEKARANG)

    const sisa = buangPesananSelesai(KUNCI, ['ord-selesai'], SEKARANG)

    expect(sisa.map((e) => e.id)).toEqual(['ord-aktif'])
  })

  it('id yang tak ada di daftar tidak berpengaruh', () => {
    catatPesanan(KUNCI, 'ord-a', SEKARANG)

    const sisa = buangPesananSelesai(KUNCI, ['ord-ghost'], SEKARANG)

    expect(sisa.map((e) => e.id)).toEqual(['ord-a'])
  })

  it('hasilnya ikut tersimpan, bukan cuma dikembalikan', () => {
    catatPesanan(KUNCI, 'ord-selesai', SEJAM_LALU)
    catatPesanan(KUNCI, 'ord-aktif', SEKARANG)

    buangPesananSelesai(KUNCI, ['ord-selesai'], SEKARANG)

    expect(bacaPesananSaya(KUNCI, SEKARANG).map((e) => e.id)).toEqual(['ord-aktif'])
  })
})
