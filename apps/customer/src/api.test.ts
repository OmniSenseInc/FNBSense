import { describe, expect, it } from 'vitest'
import { petakanMenu, type KategoriMentah } from './api'

/** Pembuat data uji — hanya field yang beda per kasus yang perlu disebut. */
function kategoriDengan(
  produk: Partial<KategoriMentah['products'][number]>[],
): KategoriMentah[] {
  return [
    {
      id: 'kat-1',
      name: 'Kopi',
      products: produk.map((p, i) => ({
        id: `prod-${i}`,
        name: 'Kopi Susu',
        description: 'Espresso, susu segar',
        price: '22000.00',
        image_url: null,
        ...p,
      })),
    },
  ]
}

describe('petakanMenu', () => {
  it('mengubah harga string decimal jadi angka', () => {
    const hasil = petakanMenu(kategoriDengan([{ price: '22000.00' }]))

    expect(hasil[0].produk[0].harga).toBe(22000)
    // Bukan sekadar "nilainya 22000" — TIPE-nya harus angka. Kalau string lolos,
    // harga + harga menghasilkan "2200022000" dan totalnya ngawur.
    expect(typeof hasil[0].produk[0].harga).toBe('number')
  })

  it.each([
    ['null', null],
    ['string kosong', ''],
    ['spasi', '   '],
    ['bukan angka', 'gratis'],
    ['negatif', '-5000'],
    ['nol', '0.00'],
  ])('membuang produk dengan harga %s', (_nama, harga) => {
    const hasil = petakanMenu(
      kategoriDengan([
        { id: 'rusak', price: harga },
        { id: 'sehat', price: '8000.00' },
      ]),
    )

    expect(hasil[0].produk.map((p) => p.id)).toEqual(['sehat'])
  })

  it('membuang kategori yang jadi kosong, bukan menyisakan judul menggantung', () => {
    expect(petakanMenu(kategoriDengan([{ price: null }]))).toEqual([])
  })

  it('deskripsi null jadi string kosong supaya tampilan tak menulis "null"', () => {
    const hasil = petakanMenu(kategoriDengan([{ description: null }]))

    expect(hasil[0].produk[0].deskripsi).toBe('')
  })

  it('hanya menyalin field yang diizinkan — kolom asing tidak ikut masuk', () => {
    // Meniru F7b: kolom harga modal muncul di payload backend.
    const mentah = kategoriDengan([{}])
    // @ts-expect-error sengaja menyuntik kolom yang tak ada di tipe kita
    mentah[0].products[0].cost_price = '9000.00'

    const produk = petakanMenu(mentah)[0].produk[0]

    expect(Object.keys(produk)).toEqual(['id', 'nama', 'deskripsi', 'harga', 'gambarUrl'])
  })
})
