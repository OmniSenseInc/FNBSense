import { useCallback, useEffect, useState } from 'react'
import { MAKS_NOTE, MAKS_QTY, type BarisKeranjang, type Keranjang } from './api'

/**
 * Keranjang yang selamat dari refresh.
 *
 * Alasannya sederhana: pelanggan mengisi keranjang di HP, layarnya mati, dia
 * buka WhatsApp sebentar, browser membuang tab-nya karena memori penuh. Tanpa
 * ini semua pilihannya hilang dan dia mengulang dari nol — di kafe rame itu
 * berarti dia menyerah lalu memanggil kasir.
 *
 * Alamat mejanya sendiri sudah tahan refresh sejak awal (qrToken hidup di URL,
 * bukan state). Ini melengkapinya untuk isi keranjang.
 */

/**
 * Satu key per meja — lihat komentar di useKeranjang.
 *
 * `v2` karena bentuk isinya berubah saat catatan per item masuk: dulu
 * `{"prod-1": 2}`, sekarang `{"prod-1": {"qty": 2, "note": "…"}}`. Menaikkan
 * awalan = nol kode migrasi, dan yang hilang cuma keranjang yang belum
 * dikirim milik pelanggan yang kebetulan sedang membukanya saat app dirilis.
 * Menulis migrasi untuk data sepele yang umurnya beberapa menit itu mahal
 * sekali dibanding barangnya.
 */
const AWALAN = 'fnb.cart.v2.'

/**
 * Satu entri tersimpan -> baris keranjang yang sah, atau null kalau tak
 * terselamatkan.
 *
 * Catatan diperlakukan lebih longgar daripada qty, dan itu disengaja: catatan
 * yang bentuknya salah cukup dikosongkan, sedangkan qty yang salah membuat
 * seluruh barisnya tak punya arti. Pelanggan lebih baik kehilangan tulisan
 * "tanpa gula" daripada kehilangan kopinya dari keranjang.
 */
function bersihkanBaris(nilai: unknown): BarisKeranjang | null {
  if (typeof nilai !== 'object' || nilai === null || Array.isArray(nilai)) return null

  const { qty: qtyMentah, note: noteMentah } = nilai as Record<string, unknown>
  if (typeof qtyMentah !== 'number' || !Number.isFinite(qtyMentah)) return null

  const qty = Math.min(MAKS_QTY, Math.floor(qtyMentah))
  if (qty <= 0) return null

  // Dipotong di sini juga, bukan cuma di susunPesanan: yang tersimpan di HP
  // ikut ditampilkan di layar ringkasan, dan catatan 10.000 karakter membuat
  // pelanggan harus scroll berkilo-kilo untuk sampai ke tombol kirim.
  const note = typeof noteMentah === 'string' ? noteMentah.trim().slice(0, MAKS_NOTE) : ''

  return { qty, note }
}

/**
 * Baca keranjang tersimpan.
 *
 * Isi localStorage diperlakukan sebagai DATA ASING, bukan data kita: pelanggan
 * bisa mengeditnya lewat devtools, dan isinya bisa sisa dari versi app yang
 * lama dengan bentuk yang sudah beda. Jadi divalidasi seperti respons API.
 *
 * Yang dijaga di sini cuma keutuhan TAMPILAN. Uangnya sudah aman di tempat
 * lain, dan itu disengaja: susunPesanan menjepit qty di MAKS_QTY dan server
 * menghitung ulang seluruh harga, jadi orang yang mengubah angka di sini
 * tidak mendapat apa pun.
 *
 * Diekspor supaya bisa diuji langsung tanpa merender komponen — di sinilah
 * seluruh logika yang bisa rusak diam-diam berada.
 */
export function baca(kunci: string): Keranjang {
  try {
    const teks = localStorage.getItem(kunci)
    if (!teks) return {}

    const isi: unknown = JSON.parse(teks)
    // Array lolos dari typeof 'object', jadi disebut terpisah. Bentuk yang
    // salah = kembalikan keranjang kosong, jangan tumbangkan halaman menu.
    if (typeof isi !== 'object' || isi === null || Array.isArray(isi)) return {}

    const bersih: Keranjang = {}
    for (const [id, nilai] of Object.entries(isi)) {
      // Baris yang tak masuk akal DIBUANG satuan, bukan membatalkan seluruh
      // keranjang: satu entri rusak tak boleh menghapus 9 pilihan yang sehat.
      const baris = bersihkanBaris(nilai)
      if (baris) bersih[id] = baris
    }
    return bersih
  } catch {
    // JSON rusak, atau localStorage sendiri melempar (lihat tulis()).
    return {}
  }
}

/**
 * Simpan keranjang. Gagal menyimpan TIDAK dianggap kegagalan fatal.
 *
 * localStorage bisa melempar, bukan cuma kosong: Safari mode privat dan
 * kuota penuh dua-duanya membuat setItem gagal. Kalau itu dibiarkan naik,
 * pelanggan bukan cuma kehilangan keranjang — layarnya blank. Lebih baik
 * app jalan tanpa persistensi daripada tidak jalan sama sekali.
 *
 * Keranjang kosong menghapus key-nya, bukan menulis "{}": tak ada gunanya
 * meninggalkan sampah di HP orang untuk meja yang batal dipesan.
 */
export function tulis(kunci: string, isi: Keranjang): void {
  try {
    if (Object.keys(isi).length === 0) localStorage.removeItem(kunci)
    else localStorage.setItem(kunci, JSON.stringify(isi))
  } catch {
    // Sengaja diam: persistensi itu kenyamanan, bukan syarat memesan.
  }
}

/**
 * Ubah satu baris keranjang. Murni — mengembalikan keranjang baru.
 *
 * Dua hal wajib dijaga bersamaan, dan itulah kenapa ia satu fungsi:
 *
 * 1. Mengubah JUMLAH lewat +/− di kartu menu tak boleh menghapus catatan yang
 *    sudah ditulis pelanggan di lembar detail. `note` sengaja opsional: tak
 *    disebut = pertahankan yang lama, bukan kosongkan.
 * 2. Jumlah nol MENGHAPUS barisnya, bukan menyimpan {qty: 0}. Kalau barisnya
 *    disimpan, catatan yatim ikut awet di HP lalu muncul lagi saat produk yang
 *    sama dipilih ulang — pelanggan tak pernah memintanya.
 *
 * Diangkat keluar dari komponen supaya bisa diuji tanpa merender apa pun:
 * inilah satu-satunya tempat catatan bisa hilang diam-diam.
 */
export function ubahBaris(
  lama: Keranjang,
  id: string,
  qty: number,
  note?: string,
): Keranjang {
  if (qty <= 0) {
    const sisa = { ...lama }
    delete sisa[id]
    return sisa
  }
  return { ...lama, [id]: { qty, note: note ?? lama[id]?.note ?? '' } }
}

export function useKeranjang(qrToken: string | undefined) {
  // Key per MEJA, bukan satu key global. Pelanggan yang pindah meja lalu
  // memindai QR baru tidak boleh mewarisi keranjang meja sebelumnya —
  // secara konteks itu pesanan orang lain.
  const kunci = AWALAN + (qrToken ?? '')

  const [isi, setIsi] = useState<Keranjang>(() => baca(kunci))

  // Pindah meja tidak me-remount komponen ini: rutenya sama (/t/:qrToken),
  // cuma paramnya yang beda, jadi React memakai ulang komponen yang sama
  // beserta state-nya. Tanpa penyesuaian ini, keranjang meja LAMA akan
  // tertulis ke key meja BARU — persis yang mau dicegah key per-meja.
  // Pola "adjust state saat prop berubah" (saat render, bukan di useEffect:
  // effect baru jalan setelah layar sempat menampilkan data yang salah).
  const [kunciTerpasang, setKunciTerpasang] = useState(kunci)
  if (kunci !== kunciTerpasang) {
    setKunciTerpasang(kunci)
    setIsi(baca(kunci))
  }

  useEffect(() => {
    tulis(kunci, isi)
  }, [kunci, isi])

  /**
   * Dipanggil setelah pesanan BERHASIL terkirim.
   *
   * Ini bagian terpenting dari file ini. Sebelum ada persistensi, keranjang
   * bersih sendiri karena komponennya mati saat pindah halaman. Begitu isinya
   * bertahan di HP, kegratisan itu hilang: pelanggan yang sudah membayar lalu
   * memindai QR meja yang sama lagi akan melihat keranjang lamanya, mengira
   * itu keranjang baru, menambah satu item, dan mengirim ulang SELURUHNYA —
   * kasir menerima pesanan yang sebagiannya sudah dibayar barusan.
   *
   * removeItem dipanggil LANGSUNG, tidak menumpang useEffect di atas: setelah
   * ini komponennya langsung berpindah rute, dan effect milik komponen yang
   * ter-unmount pada commit yang sama tidak pernah dijalankan. Menyimpan
   * pembersihan uang di dalam effect berarti kadang-kadang tidak terjadi.
   */
  const hapus = useCallback(() => {
    setIsi({})
    try {
      localStorage.removeItem(kunci)
    } catch {
      // Sama seperti tulis(): tak ada yang bisa dilakukan, dan pesanannya
      // sendiri sudah terkirim dengan selamat.
    }
  }, [kunci])

  return [isi, setIsi, hapus] as const
}
