import { describe, expect, it } from 'vitest'
import { riwayatHariIni, sedangDibuat, totalKembar, urutkanAntrean } from './antrean'
import { type Pesanan } from './api'

/** Pesanan seadanya — yang diuji cuma totalnya. */
function pesanan(id: string, grandTotal: number): Pesanan {
  return {
    id,
    order_number: id,
    customer_name: '',
    grand_total: grandTotal,
    subtotal: grandTotal,
    grossSubtotal: grandTotal,
    diskon: 0,
    promo: null,
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

describe('urutkanAntrean', () => {
  /** Pesanan menunggu bayar, dengan atau tanpa klaim pelanggan. */
  function menunggu(id: string, klaimBayar: string | null): Pesanan {
    return { ...pesanan(id, 20_000), klaimBayar }
  }

  it('menaikkan pengklaim ke pucuk', () => {
    // Uangnya kemungkinan besar sudah masuk tapi belum tercatat, dan tenggatnya
    // sudah diperpanjang untuk terakhir kali. Inilah baris yang paling mahal
    // kalau terlewat.
    const hasil = urutkanAntrean([
      menunggu('a', null),
      menunggu('b', '2026-08-01T14:32:00+07:00'),
      menunggu('c', null),
    ])

    expect(hasil.map((p) => p.id)).toEqual(['b', 'a', 'c'])
  })

  it('tidak mengacak urutan di dalam kelompok yang sama', () => {
    // Server mengirim yang paling lama menunggu lebih dulu. Pengurutan yang
    // memindahkan baris tanpa alasan akan menggeser kartu di bawah jari kasir
    // yang sedang membacanya.
    const hasil = urutkanAntrean([menunggu('a', null), menunggu('b', null), menunggu('c', null)])

    expect(hasil.map((p) => p.id)).toEqual(['a', 'b', 'c'])
  })

  it('tidak mengurutkan daftar aslinya di tempat', () => {
    // `daftar` adalah state React: mengaduk array yang sama tak mengubah
    // identitasnya, jadi render berikutnya bisa saja melewatkan perubahannya.
    const asli = [menunggu('a', null), menunggu('b', '2026-08-01T14:32:00+07:00')]

    urutkanAntrean(asli)

    expect(asli.map((p) => p.id)).toEqual(['a', 'b'])
  })
})

describe('sedangDibuat', () => {
  const sekarang = Date.parse('2026-08-01T20:00:00+07:00')

  /** Pesanan lunas, dengan atau tanpa jam siap. */
  function lunas(id: string, waktuBayar: string | null, siapPada: string | null = null): Pesanan {
    return { ...pesanan(id, 20_000), waktuBayar, siapPada }
  }

  it('membuang pesanan yang sudah ditandai siap', () => {
    const hasil = sedangDibuat(
      [
        lunas('siap', '2026-08-01T14:00:00+07:00', '2026-08-01T14:05:00+07:00'),
        lunas('belum', '2026-08-01T14:10:00+07:00'),
      ],
      sekarang,
    )

    expect(hasil.map((p) => p.id)).toEqual(['belum'])
  })

  it('menaruh yang paling lama menunggu di pucuk', () => {
    // Kebalikan dari riwayat: yang dicari di sini bukan orang yang baru saja
    // pergi, melainkan orang yang minumannya paling telat.
    const hasil = sedangDibuat(
      [
        lunas('baru', '2026-08-01T14:50:00+07:00'),
        lunas('lama', '2026-08-01T14:05:00+07:00'),
        lunas('tengah', '2026-08-01T14:30:00+07:00'),
      ],
      sekarang,
    )

    expect(hasil.map((p) => p.id)).toEqual(['lama', 'tengah', 'baru'])
  })

  it('membuang pesanan yang belum dibayar', () => {
    // Pagar urutan uang: barang tak boleh masuk daftar racik sebelum uangnya
    // tercatat. Kalau ini merah, ada jalan menyerahkan barang lalu menagih.
    expect(sedangDibuat([lunas('belum-bayar', null)], sekarang)).toEqual([])
  })

  it('membuang pesanan kemarin yang tak pernah ditandai siap', () => {
    // Kalau tidak, kartu dari shift kemarin mengendap selamanya di layar dan
    // menutupi pekerjaan hari ini.
    expect(sedangDibuat([lunas('kemarin', '2026-07-31T22:00:00+07:00')], sekarang)).toEqual([])
  })
})
