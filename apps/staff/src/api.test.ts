import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ambilAntrean, bacaToken, petakanPesanan, SESI_HABIS } from './api'

/** localStorage palsu — vitest berjalan di Node, tak ada penyimpanan browser. */
function pasangPenyimpanan(awal: Record<string, string> = {}) {
  const isi = new Map(Object.entries(awal))
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => isi.get(k) ?? null,
    setItem: (k: string, v: string) => void isi.set(k, v),
    removeItem: (k: string) => void isi.delete(k),
  })
}

function respons(status: number, body: unknown): Response {
  return {
    status,
    ok: status >= 200 && status < 300,
    json: async () => body,
  } as Response
}

function tokenDari(init: RequestInit | undefined): string {
  const headers = (init?.headers ?? {}) as Record<string, string>
  return (headers.Authorization ?? '').replace('Bearer ', '')
}

beforeEach(() => {
  vi.unstubAllGlobals()
})

describe('petakanPesanan', () => {
  const mentah = {
    id: 'ord-1',
    order_number: 'A-001',
    customer_name: 'Vincent',
    grand_total: 38500,
    created_at: '2026-07-29T14:05:00+07:00',
    items: [{ product_name: 'Kopi Susu', qty: 2, line_total: 44000, note: 'tanpa gula' }],
  }

  it('menerima total berbentuk string decimal', () => {
    // Kolomnya decimal(12,2); hari ini Eloquent men-cast ke integer, tapi satu
    // perubahan cast di server mengubahnya jadi "38500.00".
    const hasil = petakanPesanan({ ...mentah, grand_total: '38500.00' })

    expect(hasil.grand_total).toBe(38500)
    expect(typeof hasil.grand_total).toBe('number')
  })

  it.each([
    ['null', null],
    ['string kosong', ''],
    ['bukan angka', 'gratis'],
    ['tak ada', undefined],
  ])('menandai total %s sebagai NaN, bukan 0', (_nama, nilai) => {
    // Rp 0 di layar kasir = pembayaran nol rupiah diterima tanpa curiga.
    // NaN memaksa rupiah() menulis "Rp —" sehingga kejanggalannya terlihat.
    expect(petakanPesanan({ ...mentah, grand_total: nilai }).grand_total).toBeNaN()
  })

  it('catatan bukan string tidak ikut masuk sebagai teks "null"', () => {
    const hasil = petakanPesanan({
      ...mentah,
      items: [{ product_name: 'Es Teh', qty: 1, line_total: 8000, note: null }],
    })

    expect(hasil.items[0].note).toBe('')
  })

  it('memetakan rincian yang dicetak di nota', () => {
    // Nota mencetak angka-angka ini di kertas yang dipegang pelanggan. Salah
    // nama field tak bikin apa pun merah — cuma "Rp —" yang baru ketahuan saat
    // ada yang protes di depan kasir.
    const hasil = petakanPesanan({
      ...mentah,
      subtotal: 44000,
      service_charge: 2200,
      tax: 4840,
      payment_method: 'cash',
      payment_preference: 'qris_static',
      confirmed_at: '2026-07-30T14:20:00+07:00',
      items: [
        { product_name: 'Kopi Susu', qty: 2, unit_price: 22000, line_total: 44000, note: '' },
      ],
    })

    expect(hasil.subtotal).toBe(44000)
    expect(hasil.layanan).toBe(2200)
    expect(hasil.pajak).toBe(4840)
    expect(hasil.caraBayar).toBe('cash')
    expect(hasil.niatBayar).toBe('qris_static')
    expect(hasil.waktuBayar).toBe('2026-07-30T14:20:00+07:00')
    expect(hasil.items[0].hargaSatuan).toBe(22000)
  })

  it('meneruskan tenggat bayar apa adanya dari server', () => {
    // Layar TIDAK menghitung tenggat sendiri dari created_at: angkanya berasal
    // dari order_expiry_minutes milik outlet yang boleh diubah owner kapan saja.
    const hasil = petakanPesanan({ ...mentah, expires_at: '2026-07-29T14:35:00+07:00' })

    expect(hasil.expires_at).toBe('2026-07-29T14:35:00+07:00')
  })

  it('tenggat yang tak dikirim server jadi null, bukan teks "undefined"', () => {
    // String(undefined) menghasilkan "undefined" — tenggat palsu yang terlihat
    // seperti data sungguhan sampai ada yang mencoba membacanya.
    expect(petakanPesanan(mentah).expires_at).toBeNull()
  })

  it('items yang hilang tidak menumbangkan layar', () => {
    expect(petakanPesanan({ ...mentah, items: undefined }).items).toEqual([])
  })
})

describe('token kedaluwarsa di tengah shift', () => {
  it('ditukar diam-diam lalu permintaannya diulang', async () => {
    pasangPenyimpanan({ 'fnb.staff.token': 'lama' })
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init?: RequestInit) => {
        if (url.includes('/api/auth/refresh')) return respons(200, { access_token: 'baru' })
        if (tokenDari(init) !== 'baru') return respons(401, {})
        return respons(200, { data: [] })
      }),
    )

    await expect(ambilAntrean()).resolves.toEqual([])
    // Token baru wajib TERSIMPAN. Kalau tidak, tiap permintaan berikutnya
    // menukar ulang — dan penukaran mem-blacklist token, jadi kasir tertendang.
    expect(bacaToken()).toBe('baru')
  })

  it('dua permintaan bersamaan hanya menukar token SEKALI', async () => {
    // Penukaran mem-blacklist token lama. Kalau permintaan kedua ikut menukar,
    // ia menukar token yang sudah mati -> gagal -> kasir dipaksa login ulang
    // padahal sesinya sehat.
    pasangPenyimpanan({ 'fnb.staff.token': 'lama' })
    let jumlahTukar = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init?: RequestInit) => {
        if (url.includes('/api/auth/refresh')) {
          jumlahTukar++
          return respons(200, { access_token: 'baru' })
        }
        if (tokenDari(init) !== 'baru') return respons(401, {})
        return respons(200, { data: [] })
      }),
    )

    await Promise.all([ambilAntrean(), ambilAntrean()])

    expect(jumlahTukar).toBe(1)
  })

  it('penukaran yang ditolak mengosongkan token dan menandai sesi habis', async () => {
    pasangPenyimpanan({ 'fnb.staff.token': 'lama' })
    vi.stubGlobal('fetch', vi.fn(async () => respons(401, {})))

    await expect(ambilAntrean()).rejects.toThrow(SESI_HABIS)
    // Token mati wajib dibuang, kalau tidak App menganggap kasir masih masuk
    // begitu tab di-refresh dan layarnya kosong tanpa penjelasan.
    expect(bacaToken()).toBeNull()
  })

  it('galat 409 tidak diperlakukan sebagai sesi habis', async () => {
    // Kasir lain mendahului / pesanan kedaluwarsa. Token-nya sehat — memulangkan
    // kasir ke layar login di sini akan terasa seperti app rusak.
    pasangPenyimpanan({ 'fnb.staff.token': 'sehat' })
    vi.stubGlobal('fetch', vi.fn(async () => respons(409, {})))

    await expect(ambilAntrean()).rejects.toThrow(/tidak bisa diproses/)
    expect(bacaToken()).toBe('sehat')
  })
})
