import type { Pesanan } from './api'
import { labelMeja } from './format'

/**
 * Penyusun struk termal.
 *
 * Dipecah jadi DUA tahap dengan sengaja: `barisStruk()` menghasilkan teks biasa,
 * `keBytes()` menerjemahkannya jadi perintah printer. Kalau langsung jadi byte,
 * satu-satunya cara memeriksa "apakah barisnya kepanjangan" adalah menulis
 * pembaca ESC/POS di dalam test — dan test yang butuh parser sendiri adalah test
 * yang tak seorang pun percaya.
 *
 * Semua keputusan yang bisa salah dalam diam (lebar kolom, pembulatan, urutan
 * baris, karakter yang tak bisa dicetak) hidup di tahap pertama, dan tahap
 * pertama itu murni.
 */

/** Gaya cetak per baris. Sengaja sedikit — struk bukan tempat berekspresi. */
export type Gaya =
  /** Rata kiri, ukuran biasa. */
  | 'normal'
  /** Rata tengah — alamat, telepon, ucapan terima kasih. */
  | 'tengah'
  /** Rata tengah, ukuran dobel. Hanya nama kafe. */
  | 'judul'
  /** Tebal & tinggi dobel. Hanya baris TOTAL. */
  | 'tebal'

export type Baris = { teks: string; gaya: Gaya }

export type IdentitasOutlet = {
  nama: string | null
  alamat: string | null
  telepon: string | null
}

/** Kosakata server -> bahasa yang dibaca pelanggan. */
const LABEL_BAYAR: Record<string, string> = {
  cash: 'Tunai',
  qris_static: 'QRIS',
}

/**
 * Penggantian karakter yang TIDAK bisa dicetak printer termal.
 *
 * Printer bekerja dengan code page satu byte, bukan UTF-8. Yang paling sering
 * menggigit bukan emoji melainkan `×` — tanda kali yang kita pakai di seluruh
 * layar. Sekali lolos ke sini, ia keluar sebagai dua huruf acak di tengah
 * jumlah pesanan.
 */
const GANTI: Record<string, string> = {
  '×': 'x',
  '—': '-',
  '–': '-',
  '…': '...',
  '’': "'",
  '‘': "'",
  '“': '"',
  '”': '"',
}

/**
 * Teks apa pun -> teks yang pasti bisa dicetak.
 *
 * Yang tak dikenal DIBUANG, bukan diganti tanda tanya: baris yang penuh "?"
 * terlihat seperti struk rusak, sedangkan huruf yang hilang dari satu nama menu
 * masih terbaca maksudnya.
 */
export function keAscii(teks: string): string {
  return [...teks]
    .map((huruf) => GANTI[huruf] ?? huruf)
    .join('')
    .replace(/[^\x20-\x7E]/g, '')
}

/** Angka -> rupiah siap cetak. Selalu ASCII (pemisah ribuan titik). */
function uang(nilai: number): string {
  return 'Rp ' + Math.round(nilai).toLocaleString('id-ID')
}

/**
 * Dua kolom dalam satu baris, kanan menempel ke tepi kanan.
 *
 * Saat tak muat, yang dipotong bagian KIRI (nama/label), bukan kanan: yang
 * kanan selalu angka uang, dan angka yang terpotong adalah angka yang salah.
 */
export function kolom(kiri: string, kanan: string, lebar: number): string {
  if (kanan.length >= lebar) return kanan.slice(0, lebar)

  const ruang = lebar - kiri.length - kanan.length
  if (ruang >= 1) return kiri + ' '.repeat(ruang) + kanan

  return kiri.slice(0, lebar - kanan.length - 1) + ' ' + kanan
}

/**
 * Bungkus teks panjang jadi beberapa baris.
 *
 * Dibungkus, bukan dipotong: nama kafe yang terpotong di tengah kata terlihat
 * seperti struk cacat. Kata yang lebih panjang dari kertas dipenggal keras —
 * tak ada pilihan lain.
 */
export function bungkus(teks: string, lebar: number): string[] {
  const hasil: string[] = []
  let baris = ''

  for (const kata of teks.split(/\s+/).filter((k) => k !== '')) {
    if (kata.length > lebar) {
      if (baris !== '') {
        hasil.push(baris)
        baris = ''
      }
      for (let i = 0; i < kata.length; i += lebar) hasil.push(kata.slice(i, i + lebar))
      continue
    }

    if (baris === '') baris = kata
    else if (baris.length + 1 + kata.length <= lebar) baris += ' ' + kata
    else {
      hasil.push(baris)
      baris = kata
    }
  }

  if (baris !== '') hasil.push(baris)

  return hasil
}

/** Tanggal & jam singkat, mis. "02/08/26 14:23". Kosong kalau tak terbaca. */
function tanggalJam(iso: string | null): string {
  if (iso === null) return ''

  const waktu = new Date(iso)
  if (Number.isNaN(waktu.getTime())) return ''

  const dua = (n: number) => String(n).padStart(2, '0')

  return (
    `${dua(waktu.getDate())}/${dua(waktu.getMonth() + 1)}/${String(waktu.getFullYear()).slice(-2)}` +
    ` ${dua(waktu.getHours())}:${dua(waktu.getMinutes())}`
  )
}

/**
 * Semua angka uang yang akan tercetak, untuk diperiksa sekaligus.
 *
 * Mapper kita sengaja menjadikan angka rusak sebagai NaN, bukan 0 — dan di
 * struk itulah gunanya terbukti: lebih baik kertas tak keluar daripada
 * pelanggan memegang bukti bertulis "Rp 0".
 */
function angkaStruk(pesanan: Pesanan): number[] {
  return [
    pesanan.grand_total,
    pesanan.grossSubtotal,
    pesanan.diskon,
    pesanan.pajak,
    pesanan.layanan,
    ...pesanan.items.flatMap((i) => [i.qty, i.hargaSatuan, i.total]),
  ]
}

/**
 * Pesanan -> baris-baris struk.
 *
 * Angkanya DIAMBIL dari pesanan, tak pernah dihitung ulang. Kalau struk
 * menjumlahkan itemnya sendiri, suatu hari angka di kertas berbeda dari angka
 * di laporan — dan yang dipegang pelanggan adalah yang salah.
 *
 * @param lebar Karakter per baris: 32 untuk kertas 58mm, 48 untuk 80mm.
 * @throws Error siap-tampil kalau ada angka uang yang tak terbaca.
 */
export function barisStruk(pesanan: Pesanan, outlet: IdentitasOutlet, lebar: number): Baris[] {
  if (angkaStruk(pesanan).some((n) => !Number.isFinite(n))) {
    throw new Error('Angka pesanan tak terbaca. Muat ulang nota sebelum mencetak.')
  }

  const baris: Baris[] = []
  // keAscii DI SINI adalah jaring terakhirnya, dan sengaja diletakkan di satu
  // titik yang dilewati SEMUA baris. Beberapa medan sudah dibersihkan lebih
  // dulu di titik panggilnya — itu bukan pengulangan sia-sia: `…` menjadi
  // `...` mengubah panjang teks, jadi pembersihan harus terjadi sebelum
  // pembungkusan kalau lebarnya mau dihitung benar.
  const tulis = (teks: string, gaya: Gaya = 'normal') => baris.push({ teks: keAscii(teks), gaya })

  // ── Kepala ────────────────────────────────────────────────────────────
  // Nama dicetak ukuran dobel, jadi kolomnya separuh.
  if (outlet.nama !== null) {
    for (const potong of bungkus(keAscii(outlet.nama), Math.floor(lebar / 2))) {
      tulis(potong, 'judul')
    }
  }
  if (outlet.alamat !== null) {
    for (const potong of bungkus(keAscii(outlet.alamat), lebar)) tulis(potong, 'tengah')
  }
  if (outlet.telepon !== null) tulis(outlet.telepon, 'tengah')

  tulis('='.repeat(lebar))

  // ── Info pesanan ──────────────────────────────────────────────────────
  tulis(kolom('No', pesanan.order_number, lebar))

  const meja = labelMeja(pesanan.meja, pesanan.tipe)
  if (meja) tulis(kolom('Meja', keAscii(meja), lebar))

  // Jam BAYAR kalau sudah ada; struk yang dicetak sebelum lunas jatuh ke jam
  // pesan supaya barisnya tak pernah kosong.
  const waktu = tanggalJam(pesanan.waktuBayar ?? pesanan.created_at)
  if (waktu !== '') tulis(kolom('Tanggal', waktu, lebar))

  if (pesanan.caraBayar !== null) {
    tulis(kolom('Bayar', LABEL_BAYAR[pesanan.caraBayar] ?? pesanan.caraBayar, lebar))
  }
  if (pesanan.customer_name !== '') tulis(kolom('Nama', keAscii(pesanan.customer_name), lebar))

  tulis('-'.repeat(lebar))

  // ── Item ──────────────────────────────────────────────────────────────
  for (const item of pesanan.items) {
    for (const potong of bungkus(keAscii(item.nama), lebar)) tulis(potong)
    tulis(kolom(`${item.qty} x ${uang(item.hargaSatuan)}`, uang(item.total), lebar))

    // Diawali "- " sepola layar nota, supaya jelas ia milik item di atasnya.
    // Selama layar dapur belum ada, di sinilah "tanpa gula" sampai ke tangan
    // yang meracik.
    if (item.note !== '') {
      for (const potong of bungkus(keAscii(item.note), lebar - 2)) tulis('- ' + potong)
    }
  }

  tulis('-'.repeat(lebar))

  // ── Rincian uang ──────────────────────────────────────────────────────
  tulis(kolom('Subtotal', uang(pesanan.grossSubtotal), lebar))
  // Potongan promo jadi baris sendiri, dan di sinilah "Subtotal" (kotor) bisa
  // dijelaskan. Hilang saat nol — promo memang opsional, beda dari layanan &
  // pajak. Labelnya nama promo (mis. "Diskon 10%"), bukan "Diskon" generik,
  // supaya pelanggan tahu persis promo apa yang menyentuh struknya.
  if (pesanan.diskon > 0) {
    tulis(kolom(pesanan.promo ?? 'Diskon', '-' + uang(pesanan.diskon), lebar))
  }
  // Layanan & pajak SELALU dicetak, walau nol — sepola layar nota dan layar
  // status pelanggan. Pungutan wajib yang tak tercantum bikin orang mengira
  // ada yang disembunyikan, dan yang lebih buruk: kertas yang menghilangkan
  // baris nol tak lagi sama dengan layar yang menampilkannya. Kalau pelanggan
  // membandingkan keduanya, yang goyah adalah kepercayaan pada dua-duanya.
  tulis(kolom('Layanan', uang(pesanan.layanan), lebar))
  tulis(kolom('Pajak', uang(pesanan.pajak), lebar))

  tulis('='.repeat(lebar))
  // Tinggi dobel, lebar biasa -> jumlah karakter per baris tidak berubah.
  tulis(kolom('TOTAL', uang(pesanan.grand_total), lebar), 'tebal')
  tulis('='.repeat(lebar))

  tulis('Terima kasih', 'tengah')

  return baris
}

// ── ESC/POS ─────────────────────────────────────────────────────────────
// Perintah yang benar-benar dipakai saja. Membangun pustaka ESC/POS lengkap
// berarti merawat puluhan perintah yang tak satu pun dipanggil struk ini.

const ESC = 0x1b
const GS = 0x1d

/** Awalan per gaya: perataan, ukuran, tebal. */
const AWALAN: Record<Gaya, number[]> = {
  normal: [ESC, 0x61, 0, GS, 0x21, 0x00, ESC, 0x45, 0],
  tengah: [ESC, 0x61, 1, GS, 0x21, 0x00, ESC, 0x45, 0],
  // GS ! 0x11 = lebar dobel + tinggi dobel.
  judul: [ESC, 0x61, 1, GS, 0x21, 0x11, ESC, 0x45, 1],
  // 0x01 = tinggi dobel saja, supaya jumlah kolom tetap seperti lebar kertas.
  tebal: [ESC, 0x61, 0, GS, 0x21, 0x01, ESC, 0x45, 1],
}

/**
 * Baris-baris -> byte siap kirim.
 *
 * Diakhiri feed 3 baris SEBELUM potong: pisau printer berada beberapa milimeter
 * di atas kepala cetak, jadi tanpa itu baris terakhir terpotong separuh — dan
 * yang terpotong selalu bagian bawah, tempat TOTAL berada.
 */
export function keBytes(baris: Baris[]): Uint8Array {
  const keluar: number[] = [ESC, 0x40] // ESC @ — kembalikan printer ke setelan awal

  for (const { teks, gaya } of baris) {
    keluar.push(...AWALAN[gaya])
    for (const huruf of teks) keluar.push(huruf.charCodeAt(0))
    keluar.push(0x0a)
  }

  keluar.push(0x0a, 0x0a, 0x0a)
  keluar.push(GS, 0x56, 0x00) // GS V 0 — potong penuh

  return new Uint8Array(keluar)
}
