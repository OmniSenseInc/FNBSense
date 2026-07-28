import { afterEach, describe, expect, it, vi } from 'vitest'
import { MAKS_QTY } from './api'
import { baca, tulis } from './keranjang'

/**
 * localStorage tiruan. Proyek ini tak memakai jsdom (test-nya murni fungsi),
 * jadi lebih murah menyediakan yang dibutuhkan daripada menyeret seluruh DOM
 * palsu cuma untuk tiga method.
 *
 * `lempar` meniru dua keadaan nyata yang tak bisa ditiru dengan storage kosong:
 * Safari mode privat dan kuota HP yang penuh — dua-duanya membuat panggilan
 * localStorage MELEMPAR, bukan mengembalikan null.
 */
function pasangStorage({ lempar = false } = {}) {
  const isi = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => {
      if (lempar) throw new Error('SecurityError')
      return isi.get(k) ?? null
    },
    setItem: (k: string, v: string) => {
      if (lempar) throw new Error('QuotaExceededError')
      isi.set(k, v)
    },
    removeItem: (k: string) => {
      if (lempar) throw new Error('SecurityError')
      isi.delete(k)
    },
  })
  return isi
}

afterEach(() => vi.unstubAllGlobals())

describe('keranjang tersimpan', () => {
  it('yang ditulis terbaca ulang utuh — inilah gunanya seluruh file ini', () => {
    pasangStorage()

    tulis('fnb.cart.meja-a', { kopi: 2, teh: 1 })

    expect(baca('fnb.cart.meja-a')).toEqual({ kopi: 2, teh: 1 })
  })

  it('keranjang meja lain tidak bocor ke meja ini', () => {
    pasangStorage()

    tulis('fnb.cart.meja-a', { kopi: 2 })

    // Pelanggan pindah meja lalu scan QR baru: dia sedang memesan untuk meja
    // ini, bukan melanjutkan pesanan meja sebelah.
    expect(baca('fnb.cart.meja-b')).toEqual({})
  })

  it('keranjang kosong MENGHAPUS key, bukan meninggalkan "{}" di HP orang', () => {
    const isi = pasangStorage()
    tulis('fnb.cart.meja-a', { kopi: 2 })

    tulis('fnb.cart.meja-a', {})

    expect(isi.has('fnb.cart.meja-a')).toBe(false)
  })
})

describe('baca menolak isi yang tak masuk akal', () => {
  /** Menaruh teks mentah, meniru localStorage yang diedit orang atau sisa versi lama. */
  function taruh(mentah: string) {
    pasangStorage().set('fnb.cart.meja-a', mentah)
  }

  it.each([
    ['JSON rusak', '{kopi: 2'],
    ['array, bukan objek', '[["kopi",2]]'],
    ['null', 'null'],
    ['string', '"kopi"'],
    ['angka', '7'],
  ])('mengembalikan keranjang kosong untuk %s, bukan melempar', (_nama, mentah) => {
    taruh(mentah)

    // Kalau ini melempar, halaman menu blank dan pelanggan tak bisa memesan
    // sama sekali — kerusakan jauh lebih besar daripada keranjang yang hilang.
    expect(baca('fnb.cart.meja-a')).toEqual({})
  })

  it.each([
    ['qty nol', 0],
    ['qty negatif', -3],
    ['bukan angka', 'dua'],
    ['null', null],
    // Dua kasus di bawah ini yang benar-benar menguji penjaga typeof, dan
    // ketahuannya lewat mutation test: tanpa keduanya, membuang penjaga itu
    // tetap membuat suite hijau. Sebabnya Math.floor('dua') = NaN dan
    // Math.floor(null) = 0 — dua-duanya sudah tersaring penjaga qty > 0 di
    // hilir. Tapi Math.floor(true) = 1 dan Math.floor('5') = 5 LOLOS, jadi
    // pelanggan bisa punya item hantu ber-qty 1 dari data yang rusak.
    ['boolean', true],
    ['angka dalam bentuk string', '5'],
  ])('membuang entri %s tapi mempertahankan yang sehat', (_nama, buruk) => {
    taruh(JSON.stringify({ rusak: buruk, kopi: 2 }))

    // Satu entri busuk tak boleh menghapus pilihan lain yang sudah susah
    // payah dikumpulkan pelanggan.
    expect(baca('fnb.cart.meja-a')).toEqual({ kopi: 2 })
  })

  it('menjepit qty yang melebihi batas server, bukan meloloskannya ke 422', () => {
    taruh(JSON.stringify({ kopi: 99999 }))

    expect(baca('fnb.cart.meja-a')).toEqual({ kopi: MAKS_QTY })
  })

  it('membulatkan qty pecahan ke bawah — server hanya menerima integer', () => {
    taruh(JSON.stringify({ kopi: 2.9 }))

    expect(baca('fnb.cart.meja-a')).toEqual({ kopi: 2 })
  })
})

describe('localStorage yang melempar', () => {
  // Safari mode privat & kuota penuh. Persistensi itu kenyamanan; kehilangan
  // kenyamanan boleh, kehilangan kemampuan memesan tidak.
  it('tulis gagal diam-diam, tidak menumbangkan halaman', () => {
    pasangStorage({ lempar: true })

    expect(() => tulis('fnb.cart.meja-a', { kopi: 2 })).not.toThrow()
  })

  it('baca gagal jadi keranjang kosong, tidak menumbangkan halaman', () => {
    pasangStorage({ lempar: true })

    expect(baca('fnb.cart.meja-a')).toEqual({})
  })
})
