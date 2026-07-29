import { afterEach, describe, expect, it, vi } from 'vitest'
import { MAKS_NOTE, MAKS_QTY } from './api'
import { baca, tulis, ubahBaris } from './keranjang'

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

const KUNCI = 'fnb.cart.v2.meja-a'

/** Baris keranjang ringkas — catatan kosong adalah keadaan biasa. */
const baris = (qty: number, note = '') => ({ qty, note })

afterEach(() => vi.unstubAllGlobals())

describe('keranjang tersimpan', () => {
  it('yang ditulis terbaca ulang utuh — inilah gunanya seluruh file ini', () => {
    pasangStorage()

    tulis(KUNCI, { kopi: baris(2, 'tanpa gula'), teh: baris(1) })

    expect(baca(KUNCI)).toEqual({ kopi: baris(2, 'tanpa gula'), teh: baris(1) })
  })

  it('keranjang meja lain tidak bocor ke meja ini', () => {
    pasangStorage()

    tulis(KUNCI, { kopi: baris(2) })

    // Pelanggan pindah meja lalu scan QR baru: dia sedang memesan untuk meja
    // ini, bukan melanjutkan pesanan meja sebelah.
    expect(baca('fnb.cart.v2.meja-b')).toEqual({})
  })

  it('keranjang kosong MENGHAPUS key, bukan meninggalkan "{}" di HP orang', () => {
    const isi = pasangStorage()
    tulis(KUNCI, { kopi: baris(2) })

    tulis(KUNCI, {})

    expect(isi.has(KUNCI)).toBe(false)
  })
})

describe('baca menolak isi yang tak masuk akal', () => {
  /** Menaruh teks mentah, meniru localStorage yang diedit orang atau sisa versi lama. */
  function taruh(mentah: string) {
    pasangStorage().set(KUNCI, mentah)
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
    expect(baca(KUNCI)).toEqual({})
  })

  it.each([
    ['qty nol', { qty: 0 }],
    ['qty negatif', { qty: -3 }],
    ['qty bukan angka', { qty: 'dua' }],
    ['qty null', { qty: null }],
    // Dua kasus di bawah ini yang benar-benar menguji penjaga typeof, dan
    // ketahuannya lewat mutation test: tanpa keduanya, membuang penjaga itu
    // tetap membuat suite hijau. Sebabnya Math.floor('dua') = NaN dan
    // Math.floor(null) = 0 — dua-duanya sudah tersaring penjaga qty > 0 di
    // hilir. Tapi Math.floor(true) = 1 dan Math.floor('5') = 5 LOLOS, jadi
    // pelanggan bisa punya item hantu ber-qty 1 dari data yang rusak.
    ['qty boolean', { qty: true }],
    ['qty angka dalam bentuk string', { qty: '5' }],
    ['qty tak ada sama sekali', { note: 'tanpa gula' }],
    // Bentuk v1: baris pernah berupa angka telanjang. Key sudah dinaikkan ke
    // v2 jadi ini tak seharusnya terjadi — tapi kalau tetap terjadi, jangan
    // sampai qty-nya undefined lalu jadi NaN di perkalian harga.
    ['angka telanjang (bentuk lama)', 2],
    ['array', [2]],
    ['null', null],
  ])('membuang entri %s tapi mempertahankan yang sehat', (_nama, buruk) => {
    taruh(JSON.stringify({ rusak: buruk, kopi: baris(2) }))

    // Satu entri busuk tak boleh menghapus pilihan lain yang sudah susah
    // payah dikumpulkan pelanggan.
    expect(baca(KUNCI)).toEqual({ kopi: baris(2) })
  })

  it('menjepit qty yang melebihi batas server, bukan meloloskannya ke 422', () => {
    taruh(JSON.stringify({ kopi: baris(99999) }))

    expect(baca(KUNCI)).toEqual({ kopi: baris(MAKS_QTY) })
  })

  it('membulatkan qty pecahan ke bawah — server hanya menerima integer', () => {
    taruh(JSON.stringify({ kopi: baris(2.9) }))

    expect(baca(KUNCI)).toEqual({ kopi: baris(2) })
  })
})

describe('catatan yang tersimpan', () => {
  function taruh(mentah: string) {
    pasangStorage().set(KUNCI, mentah)
  }

  it('bertahan melewati refresh — itu seluruh alasan catatan ikut disimpan', () => {
    pasangStorage()

    tulis(KUNCI, { kopi: baris(1, 'tanpa gula') })

    expect(baca(KUNCI).kopi.note).toBe('tanpa gula')
  })

  it.each([
    ['angka', 7],
    ['objek', { a: 1 }],
    ['null', null],
    ['boolean', true],
  ])('catatan %s dikosongkan, TAPI itemnya tetap di keranjang', (_nama, buruk) => {
    taruh(JSON.stringify({ kopi: { qty: 2, note: buruk } }))

    // Catatan rusak lebih ringan daripada qty rusak: pelanggan lebih baik
    // kehilangan tulisan "tanpa gula" daripada kehilangan kopinya.
    expect(baca(KUNCI)).toEqual({ kopi: baris(2) })
  })

  it('catatan yang hilang sama sekali jadi string kosong, bukan undefined', () => {
    // undefined akan lolos ke .trim() di susunPesanan dan menumbangkan
    // pengiriman pesanan — persis di jalur uang.
    taruh(JSON.stringify({ kopi: { qty: 2 } }))

    expect(baca(KUNCI).kopi.note).toBe('')
  })

  it('memotong catatan raksasa, bukan membiarkannya membanjiri layar ringkasan', () => {
    taruh(JSON.stringify({ kopi: { qty: 1, note: 'a'.repeat(5000) } }))

    expect(baca(KUNCI).kopi.note).toHaveLength(MAKS_NOTE)
  })

  it('merapikan spasi di ujung catatan', () => {
    taruh(JSON.stringify({ kopi: { qty: 1, note: '  tanpa gula  ' } }))

    expect(baca(KUNCI).kopi.note).toBe('tanpa gula')
  })
})

describe('ubahBaris', () => {
  it('menaikkan jumlah TIDAK menghapus catatan yang sudah ditulis', () => {
    // Kegagalan yang paling gampang lolos: pelanggan menulis "tanpa gula" di
    // lembar detail, lalu menekan + di kartu menu, dan catatannya lenyap
    // tanpa satu pun pesan di layar.
    const isi = { kopi: baris(1, 'tanpa gula') }

    expect(ubahBaris(isi, 'kopi', 3)).toEqual({ kopi: baris(3, 'tanpa gula') })
  })

  it('catatan baru menimpa catatan lama saat memang disebut', () => {
    const isi = { kopi: baris(1, 'tanpa gula') }

    expect(ubahBaris(isi, 'kopi', 1, 'pakai gula')).toEqual({
      kopi: baris(1, 'pakai gula'),
    })
  })

  it('catatan bisa DIKOSONGKAN — string kosong bukan berarti "pertahankan"', () => {
    const isi = { kopi: baris(1, 'tanpa gula') }

    expect(ubahBaris(isi, 'kopi', 1, '')).toEqual({ kopi: baris(1) })
  })

  it('jumlah nol MENGHAPUS barisnya, tak meninggalkan catatan yatim', () => {
    const isi = { kopi: baris(1, 'tanpa gula'), teh: baris(2) }

    // Kalau barisnya cuma di-set qty 0, catatannya ikut awet di HP dan muncul
    // lagi saat kopi dipilih ulang — pelanggan tak pernah memintanya.
    expect(ubahBaris(isi, 'kopi', 0)).toEqual({ teh: baris(2) })
  })

  it('item baru tanpa catatan lahir dengan catatan kosong, bukan undefined', () => {
    expect(ubahBaris({}, 'kopi', 2)).toEqual({ kopi: baris(2) })
  })

  it('tidak mengubah keranjang lama — state React harus objek baru', () => {
    const isi = { kopi: baris(1, 'tanpa gula') }

    ubahBaris(isi, 'kopi', 5)

    expect(isi).toEqual({ kopi: baris(1, 'tanpa gula') })
  })
})

describe('localStorage yang melempar', () => {
  // Safari mode privat & kuota penuh. Persistensi itu kenyamanan; kehilangan
  // kenyamanan boleh, kehilangan kemampuan memesan tidak.
  it('tulis gagal diam-diam, tidak menumbangkan halaman', () => {
    pasangStorage({ lempar: true })

    expect(() => tulis(KUNCI, { kopi: baris(2) })).not.toThrow()
  })

  it('baca gagal jadi keranjang kosong, tidak menumbangkan halaman', () => {
    pasangStorage({ lempar: true })

    expect(baca(KUNCI)).toEqual({})
  })
})
