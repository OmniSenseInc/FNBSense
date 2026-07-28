import { useCallback, useEffect, useState } from 'react'
import { MAKS_QTY } from './api'

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

type Qty = Record<string, number>

/** Satu key per meja — lihat komentar di useKeranjang. */
const AWALAN = 'fnb.cart.'

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
export function baca(kunci: string): Qty {
  try {
    const teks = localStorage.getItem(kunci)
    if (!teks) return {}

    const isi: unknown = JSON.parse(teks)
    // Array lolos dari typeof 'object', jadi disebut terpisah. Bentuk yang
    // salah = kembalikan keranjang kosong, jangan tumbangkan halaman menu.
    if (typeof isi !== 'object' || isi === null || Array.isArray(isi)) return {}

    const bersih: Qty = {}
    for (const [id, nilai] of Object.entries(isi)) {
      // Baris yang tak masuk akal DIBUANG satuan, bukan membatalkan seluruh
      // keranjang: satu entri rusak tak boleh menghapus 9 pilihan yang sehat.
      if (typeof nilai !== 'number' || !Number.isFinite(nilai)) continue
      const qty = Math.min(MAKS_QTY, Math.floor(nilai))
      if (qty > 0) bersih[id] = qty
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
export function tulis(kunci: string, qty: Qty): void {
  try {
    if (Object.keys(qty).length === 0) localStorage.removeItem(kunci)
    else localStorage.setItem(kunci, JSON.stringify(qty))
  } catch {
    // Sengaja diam: persistensi itu kenyamanan, bukan syarat memesan.
  }
}

export function useKeranjang(qrToken: string | undefined) {
  // Key per MEJA, bukan satu key global. Pelanggan yang pindah meja lalu
  // memindai QR baru tidak boleh mewarisi keranjang meja sebelumnya —
  // secara konteks itu pesanan orang lain.
  const kunci = AWALAN + (qrToken ?? '')

  const [qty, setQty] = useState<Qty>(() => baca(kunci))

  // Pindah meja tidak me-remount komponen ini: rutenya sama (/t/:qrToken),
  // cuma paramnya yang beda, jadi React memakai ulang komponen yang sama
  // beserta state-nya. Tanpa penyesuaian ini, keranjang meja LAMA akan
  // tertulis ke key meja BARU — persis yang mau dicegah key per-meja.
  // Pola "adjust state saat prop berubah" (saat render, bukan di useEffect:
  // effect baru jalan setelah layar sempat menampilkan data yang salah).
  const [kunciTerpasang, setKunciTerpasang] = useState(kunci)
  if (kunci !== kunciTerpasang) {
    setKunciTerpasang(kunci)
    setQty(baca(kunci))
  }

  useEffect(() => {
    tulis(kunci, qty)
  }, [kunci, qty])

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
    setQty({})
    try {
      localStorage.removeItem(kunci)
    } catch {
      // Sama seperti tulis(): tak ada yang bisa dilakukan, dan pesanannya
      // sendiri sudah terkirim dengan selamat.
    }
  }, [kunci])

  return [qty, setQty, hapus] as const
}
