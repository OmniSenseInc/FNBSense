import { describe, expect, it, vi } from 'vitest'
import { ambilMenu, bacaWaktuKlaim, petakanMenu, type KategoriMentah } from './api'

/**
 * Penanda habis — data asing, sama seperti harga menu.
 *
 * Akibatnya di layar: produk yang bertanda kehilangan tombol tambahnya. Salah
 * arah di sini mahal ke DUA sisi — menandai yang sebenarnya ada berarti
 * menyembunyikan barang yang bisa dijual, dan melewatkan yang habis berarti
 * pelanggan baru ditolak di detik terakhir.
 */
/**
 * outlet_id WAJIB ikut di URL. Tanpanya Catalog membalas menu tanpa penanda
 * sama sekali — fitur habis mati diam-diam dan tak satu pun test bentuk-data
 * yang menyadarinya, karena semuanya masih hijau dengan medan yang absen.
 */
describe('ambilMenu', () => {
  it('mengirim tenant DAN outlet ke Catalog', async () => {
    const panggilan: string[] = []
    vi.stubGlobal('fetch', async (url: string) => {
      panggilan.push(url)
      return { ok: true, json: async () => ({ data: [] }) }
    })

    await ambilMenu('tenant-1', 'outlet-9')
    vi.unstubAllGlobals()

    expect(panggilan[0]).toContain('tenant=tenant-1')
    expect(panggilan[0]).toContain('outlet=outlet-9')
  })
})

describe('petakanMenu — penanda habis', () => {
  const kategori = (produk: Record<string, unknown>): KategoriMentah[] => [
    {
      id: 'k1',
      name: 'Kopi',
      products: [
        {
          id: 'p1',
          name: 'Espresso',
          description: null,
          price: '18000.00',
          image_url: null,
          ...produk,
        },
      ] as KategoriMentah['products'],
    },
  ]

  it('true dari server jadi habis', () => {
    expect(petakanMenu(kategori({ is_out_of_stock: true }))[0].produk[0].habis).toBe(true)
  })

  it('false dari server jadi tersedia', () => {
    expect(petakanMenu(kategori({ is_out_of_stock: false }))[0].produk[0].habis).toBe(false)
  })

  it('medan yang tak dikirim sama sekali -> tersedia, bukan habis', () => {
    // Server lama (atau permintaan tanpa ?outlet=) tak mengirim medan ini.
    // Menganggapnya habis akan mengosongkan seluruh menu.
    expect(petakanMenu(kategori({}))[0].produk[0].habis).toBe(false)
  })

  it('nilai sampah tidak dipaksa jadi true', () => {
    // Boolean('tidak') === true. Karena itu perbandingannya === true, bukan
    // konversi — balasan rusak tak boleh menyembunyikan menu yang bisa dijual.
    expect(petakanMenu(kategori({ is_out_of_stock: 'tidak' }))[0].produk[0].habis).toBe(false)
    expect(petakanMenu(kategori({ is_out_of_stock: 1 }))[0].produk[0].habis).toBe(false)
    expect(petakanMenu(kategori({ is_out_of_stock: null }))[0].produk[0].habis).toBe(false)
  })
})

/**
 * Jam klaim datang dari server, jadi diperlakukan sebagai data asing.
 *
 * Yang dijaga bukan formatnya melainkan AKIBATNYA di layar: nilai apa pun yang
 * lolos dari sini menentukan tombol "Saya sudah bayar" ditampilkan atau tidak.
 * String kosong yang lolos = tombolnya hilang untuk pesanan yang belum pernah
 * dilaporkan, dan pelanggan kehilangan satu-satunya cara memberi tahu kasir.
 */
describe('bacaWaktuKlaim', () => {
  it('mengembalikan jam klaim apa adanya', () => {
    expect(bacaWaktuKlaim({ claimed_at: '2026-01-02T14:32:00+07:00' }))
      .toBe('2026-01-02T14:32:00+07:00')
  })

  it('belum pernah diklaim -> null', () => {
    expect(bacaWaktuKlaim({ claimed_at: null })).toBeNull()
  })

  it('string kosong diperlakukan seperti belum diklaim', () => {
    expect(bacaWaktuKlaim({ claimed_at: '   ' })).toBeNull()
  })

  it('respons server lama tanpa blok payment tak menumbangkan layar', () => {
    expect(bacaWaktuKlaim(undefined)).toBeNull()
    expect(bacaWaktuKlaim(null)).toBeNull()
  })

  it('tipe tak terduga ditolak, bukan dipaksa jadi teks', () => {
    expect(bacaWaktuKlaim({ claimed_at: 1767330720 })).toBeNull()
  })
})

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

    expect(Object.keys(produk)).toEqual([
      'id',
      'nama',
      'deskripsi',
      'harga',
      'gambarUrl',
      'habis',
    ])
  })
})
