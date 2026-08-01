/**
 * Daftar pesanan yang PERNAH dikirim dari HP ini, per meja.
 *
 * Kenapa di HP dan bukan ditanyakan ke server: pelanggan tak punya login, jadi
 * satu-satunya pertanyaan yang bisa dijawab server adalah "pesanan meja ini apa
 * saja" — dan itu membocorkan pesanan orang lain yang duduk di meja yang sama,
 * juga kepada siapa pun yang menyimpan URL mejanya. Menyimpan di HP membuat
 * jawabannya otomatis benar: yang terlihat cuma milik orang yang memesannya.
 *
 * Harga yang dibayar: ganti HP atau mode penyamaran = daftarnya hilang. Itu
 * diterima sadar; alternatifnya membocorkan pesanan orang.
 *
 * Yang disimpan HANYA id dan jam pesan. Isinya — item, status, total — selalu
 * diambil ulang dari server saat popup dibuka. Dua akibat yang dua-duanya kita
 * mau: statusnya tak pernah basi, dan nol angka uang menetap di HP yang bisa
 * diedit orang.
 */

/**
 * Awalan versi, pola yang sama seperti keranjang: skema berubah = naikkan
 * awalannya, nol kode migrasi. Yang hilang cuma daftar pesanan sehari.
 */
const AWALAN = 'fnb.orders.v1.'

/**
 * Batas jumlah yang disimpan. Popup memanggil server satu kali per pesanan,
 * jadi angkanya sekaligus batas permintaan sekali buka.
 */
const MAKS = 10

export type EntriPesanan = {
  id: string
  /** Epoch ms saat pesanan dikirim. Dipakai mengurutkan & membuang yang basi. */
  dibuatPada: number
}

export function kunciPesanan(qrToken: string): string {
  return `${AWALAN}${qrToken}`
}

/** Satu entri tersimpan -> entri sah, atau null kalau tak terselamatkan. */
function bersihkan(nilai: unknown): EntriPesanan | null {
  if (typeof nilai !== 'object' || nilai === null || Array.isArray(nilai)) return null

  const { id, dibuatPada } = nilai as Record<string, unknown>
  if (typeof id !== 'string' || id.trim() === '') return null
  if (typeof dibuatPada !== 'number' || !Number.isFinite(dibuatPada)) return null

  return { id, dibuatPada }
}

/**
 * Baca daftar pesanan meja ini, terbaru dulu, hanya yang dari HARI ini.
 *
 * Dibandingkan per komponen tanggal LOKAL, bukan lewat selisih 24 jam: hari
 * pelanggan berakhir di tengah malam, dan pesanan kemarin malam tak berguna
 * bagi orang yang sedang duduk di meja pagi ini.
 *
 * Isi localStorage diperlakukan sebagai DATA ASING — bisa diedit orang lewat
 * devtools, bisa sisa versi lama. Entri yang tak masuk akal dibuang SATUAN,
 * bukan membatalkan seluruh daftar.
 */
export function bacaPesananSaya(kunci: string, sekarang: number = Date.now()): EntriPesanan[] {
  try {
    const teks = localStorage.getItem(kunci)
    if (!teks) return []

    const isi: unknown = JSON.parse(teks)
    if (!Array.isArray(isi)) return []

    const acuan = new Date(sekarang)

    return isi
      .map(bersihkan)
      .filter((entri): entri is EntriPesanan => {
        if (entri === null) return false

        const waktu = new Date(entri.dibuatPada)
        return (
          waktu.getFullYear() === acuan.getFullYear() &&
          waktu.getMonth() === acuan.getMonth() &&
          waktu.getDate() === acuan.getDate()
        )
      })
      .sort((a, b) => b.dibuatPada - a.dibuatPada)
      .slice(0, MAKS)
  } catch {
    // JSON rusak, atau localStorage sendiri melempar (Safari private mode).
    return []
  }
}

/**
 * Catat satu pesanan baru, kembalikan daftar terbaru.
 *
 * Gagal menyimpan TIDAK dianggap kegagalan fatal — pesanannya sudah sampai di
 * server, dan kehilangan daftar tak boleh menumbangkan layar orang yang baru
 * saja memesan. Yang hilang cuma kemudahan mengecek ulang.
 */
export function catatPesanan(
  kunci: string,
  id: string,
  sekarang: number = Date.now(),
): EntriPesanan[] {
  // Dedup by id: pengiriman yang diulang (atau id yang entah bagaimana sama)
  // tak boleh muncul dua kali sebagai dua pesanan berbeda di popup.
  const lama = bacaPesananSaya(kunci, sekarang).filter((entri) => entri.id !== id)
  const baru = [{ id, dibuatPada: sekarang }, ...lama].slice(0, MAKS)

  try {
    localStorage.setItem(kunci, JSON.stringify(baru))
  } catch {
    // Kuota penuh / storage ditolak. Diamkan.
  }

  return baru
}
