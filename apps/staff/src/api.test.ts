import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ambilAntrean,
  bacaToken,
  cekAkunAktif,
  peranSaya,
  petakanPesanan,
  petakanSetelan,
  SESI_HABIS,
  simpanSetelan,
} from './api'

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

describe('petakanSetelan', () => {
  const mentah = {
    tax_percent: '11.00',
    service_charge_percent: '5.00',
    order_expiry_minutes: 30,
    qris_image_url: '/storage/qris/abc.png',
    limits: {
      tax_percent_max: 30,
      service_charge_percent_max: 30,
      order_expiry_minutes_min: 1,
      order_expiry_minutes_max: 1440,
      qris_max_kilobytes: 2048,
      qris_max_pixels: 2000,
    },
  }

  it('persen datang sebagai string decimal, bukan angka', () => {
    // Kolomnya decimal(5,2): Eloquent mengirim "11.00". Dibiarkan string, ia
    // akan lolos ke atribut max/min input dan diam-diam gagal dibandingkan.
    const hasil = petakanSetelan(mentah)

    expect(hasil.pajakPersen).toBe(11)
    expect(hasil.layananPersen).toBe(5)
    expect(hasil.kedaluwarsaMenit).toBe(30)
  })

  it('tarif yang tak terbaca jadi NaN, BUKAN nol', () => {
    // Nol di layar ini bukan sekadar salah tampilan: owner yang melihat
    // "Pajak 0%" padahal servernya menagih 11% tak punya alasan untuk curiga,
    // lalu menekan Simpan dan benar-benar menghapus pajaknya.
    const hasil = petakanSetelan({ ...mentah, tax_percent: null })

    expect(Number.isNaN(hasil.pajakPersen)).toBe(true)
  })

  it('outlet tanpa QRIS memberi null, bukan string kosong', () => {
    expect(petakanSetelan({ ...mentah, qris_image_url: null }).qrisUrl).toBeNull()
  })

  it('identitas kosong dari server jadi null, bukan string kosong', () => {
    // Dua keadaan yang artinya sama ("belum diisi") harus tiba di layar sebagai
    // satu nilai, kalau tidak tiap tempat yang memakainya harus memeriksa dua.
    const hasil = petakanSetelan({ ...mentah, outlet_name: '', outlet_phone: '   ' })

    expect(hasil.namaOutlet).toBeNull()
    expect(hasil.teleponOutlet).toBeNull()
  })

  it('batas dibaca dari server', () => {
    expect(petakanSetelan(mentah).batas.tax_percent_max).toBe(30)
    expect(petakanSetelan(mentah).batas.order_expiry_minutes_max).toBe(1440)
  })

  it('server lama tanpa limits tidak menjatuhkan layar', () => {
    // Batasnya hilang jadi NaN, dan layar membuang atribut max — form tetap
    // bisa dipakai, dan yang menolak tetap server. Melempar di sini akan
    // membuat layar setelan kosong total gara-gara angka pemandu.
    const { limits, ...tanpaBatas } = mentah
    void limits

    expect(Number.isNaN(petakanSetelan(tanpaBatas).batas.tax_percent_max)).toBe(true)
    expect(petakanSetelan(tanpaBatas).pajakPersen).toBe(11)
  })
})

describe('simpanSetelan', () => {
  it('kotak identitas yang dikosongkan dikirim sebagai null, bukan ""', async () => {
    // Bedanya nyata di kertas: null berarti barisnya tak dicetak, "" berarti
    // baris kosong yang tetap memakan tempat di kepala struk. Owner yang
    // menghapus isinya jelas memaksudkan yang pertama.
    pasangPenyimpanan({ 'fnb.staff.token': 'sehat' })
    // Parameternya disebutkan supaya init-nya bisa diperiksa: mock tanpa
    // parameter membuat mock.calls bertipe tuple kosong.
    const kirim = vi.fn(async (_jalur: string, init?: RequestInit) => {
      void init

      return respons(200, { data: {} })
    })
    vi.stubGlobal('fetch', kirim)

    await simpanSetelan({
      pajakPersen: 11,
      layananPersen: 5,
      kedaluwarsaMenit: 30,
      namaOutlet: 'Kopi Senja',
      alamatOutlet: '   ',
      teleponOutlet: '',
    })

    const badan: Record<string, unknown> = JSON.parse(
      (kirim.mock.calls[0][1] as RequestInit).body as string,
    )
    expect(badan.outlet_name).toBe('Kopi Senja')
    expect(badan.outlet_address).toBeNull()
    expect(badan.outlet_phone).toBeNull()
  })
})

describe('peranSaya', () => {
  /** JWT palsu: header dan tanda tangan tak pernah dibaca fungsi ini. */
  function token(payload: unknown): string {
    const b64 = btoa(JSON.stringify(payload))
      .replace(/\+/g, '-')
      .replace(/\//g, '_')
      .replace(/=+$/, '')

    return `abc.${b64}.xyz`
  }

  it('membaca peran dari klaim', () => {
    pasangPenyimpanan({ 'fnb.staff.token': token({ role: 'owner', sub: '1' }) })

    expect(peranSaya()).toBe('owner')
  })

  it('kasir bukan owner', () => {
    pasangPenyimpanan({ 'fnb.staff.token': token({ role: 'cashier' }) })

    expect(peranSaya()).toBe('cashier')
  })

  it('token cacat menjawab null, tidak melempar', () => {
    // Kalau ini melempar, seluruh layar antrean gagal dirender — gara-gara
    // sesuatu yang cuma menentukan tampil atau tidaknya satu tautan.
    pasangPenyimpanan({ 'fnb.staff.token': 'bukan.jwt' })

    expect(peranSaya()).toBeNull()
  })

  it('tanpa token menjawab null', () => {
    pasangPenyimpanan()

    expect(peranSaya()).toBeNull()
  })

  it('klaim tanpa role menjawab null, bukan undefined yang lolos', () => {
    pasangPenyimpanan({ 'fnb.staff.token': token({ sub: '1' }) })

    expect(peranSaya()).toBeNull()
  })
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

  it('memetakan potongan promo dan nama promonya', () => {
    // Server yang menghitung diskon; layar cuma meneruskannya. Tanpa field ini
    // kasir melihat total "kurang" tanpa tahu ada promo yang dipotong di balik.
    const hasil = petakanPesanan({
      ...mentah,
      gross_subtotal: 50000,
      discount_total: 6000,
      subtotal: 44000,
      promotion: { name: 'Diskon 10%' },
    })

    expect(hasil.grossSubtotal).toBe(50000)
    expect(hasil.diskon).toBe(6000)
    expect(hasil.promo).toBe('Diskon 10%')
  })

  it('promo yang tak ada menjadi null, bukan teks "undefined"', () => {
    // String(undefined) menghasilkan "undefined" — nama promo palsu yang tampil
    // di layar seolah ada diskon padahal tidak.
    expect(petakanPesanan(mentah).promo).toBeNull()
  })

  it('memetakan label meja dan tipe pesanan', () => {
    // Nama fieldnya `table_label`, bukan `table`: server sengaja mengirim
    // labelnya saja supaya qr_token meja tak punya jalan ikut terserialisasi.
    const hasil = petakanPesanan({ ...mentah, table_label: 'Meja 4', order_type: 'dine_in' })

    expect(hasil.meja).toBe('Meja 4')
    expect(hasil.tipe).toBe('dine_in')
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

describe('cekAkunAktif', () => {
  it('akun hidup (200) berarti masih boleh lanjut', async () => {
    pasangPenyimpanan({ 'fnb.staff.token': 'sehat' })
    vi.stubGlobal('fetch', vi.fn(async () => respons(200, {})))

    await expect(cekAkunAktif()).resolves.toBe(true)
  })

  it('akun dihapus/dinonaktifkan (403) = mati, harus keluar', async () => {
    pasangPenyimpanan({ 'fnb.staff.token': 'sehat' })
    vi.stubGlobal('fetch', vi.fn(async () => respons(403, {})))

    await expect(cekAkunAktif()).resolves.toBe(false)
  })

  it('token kedaluwarsa (401) bukan soal akun — jangan suruh keluar', async () => {
    pasangPenyimpanan({ 'fnb.staff.token': 'lama' })
    vi.stubGlobal('fetch', vi.fn(async () => respons(401, {})))

    await expect(cekAkunAktif()).resolves.toBe(true)
  })

  it('jaringan gagal bukan salah akun', async () => {
    pasangPenyimpanan({ 'fnb.staff.token': 'sehat' })
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('jaringan putus')
      }),
    )

    await expect(cekAkunAktif()).resolves.toBe(true)
  })

  it('tanpa token = mati (tak ada sesi yang bisa dipertahankan)', async () => {
    pasangPenyimpanan({})
    vi.stubGlobal('fetch', vi.fn(async () => respons(200, {})))

    await expect(cekAkunAktif()).resolves.toBe(false)
  })
})
