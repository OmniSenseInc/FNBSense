/**
 * Arti status pesanan bagi pelanggan — dipakai halaman status DAN popup
 * "Pesanan saya".
 *
 * Tinggal di berkas sendiri karena dua alasan. Yang pertama teknis: berkas
 * komponen yang ikut mengekspor fungsi biasa mematikan Fast Refresh untuk
 * berkas itu (alasan yang sama seperti konteksMeja.ts). Yang kedua lebih
 * penting: kalau tiap layar menerjemahkan status sendiri-sendiri, dua layar
 * bisa menyebut pesanan yang sama dengan dua kalimat berbeda.
 */

/**
 * Rasa status, bukan status itu sendiri.
 *
 * Layar cuma perlu tahu tiga hal: masih ditunggu, berakhir baik, atau berakhir
 * buruk. Memisahkannya dari kode status server berarti status baru di server
 * tak perlu tahu apa-apa soal warna, dan warna di sini tak perlu tahu apa-apa
 * soal kosakata server.
 */
export type Rasa = 'menunggu' | 'berhasil' | 'gagal'

/**
 * Terjemahan status server -> kalimat yang berarti bagi pelanggan.
 *
 * Sengaja TIDAK menampilkan kode mentah seperti "PENDING": pelanggan tak perlu
 * tahu kosakata internal kita. Status tak dikenal jatuh ke teks apa adanya
 * supaya status baru di server tak bikin layar kosong — dan rasanya 'menunggu',
 * bukan 'gagal': mengarang kesimpulan buruk dari status yang tak kita kenal
 * jauh lebih merusak daripada diam.
 *
 * Catatan cakupan: server hari ini cuma punya empat status, dan tak satu pun
 * bercerita soal dapur. "Sedang dibuat" adalah arti dari `paid`; "siap diantar"
 * BELUM ADA dan tak boleh dikarang di sini — ia menunggu layar dapur (KDS)
 * yang menandai pesanan selesai.
 */
export function jelaskanStatus(
  status: string,
  adaQris: boolean,
  sudahSiap = false,
): { judul: string; isi: string; rasa: Rasa } {
  switch (status.toLowerCase()) {
    case 'pending':
      return {
        judul: 'Menunggu pembayaran',
        // Kalimatnya ikut QR-nya. Menyuruh "bayar di meja kasir" padahal
        // QR-nya terpampang di layar membuat pelanggan bangkit tanpa perlu —
        // persis kebalikan dari alasan fitur itu ada.
        isi: adaQris
          ? 'Bayar dengan QRIS berikut. Status akan berubah setelah kasir memastikan pembayaranmu masuk.'
          : 'Bayar di meja kasir. Status akan berubah setelah kasir memastikan pembayaranmu masuk.',
        rasa: 'menunggu',
      }
    case 'paid':
      // Sesudah dibayar, yang dipedulikan pelanggan bukan lagi uangnya
      // melainkan pesanannya. Karena itu judulnya ikut berpindah begitu dapur
      // menandai siap — kabar "Pembayaran Berhasil!" yang bertahan sementara
      // kopinya sudah di konter membuat orang tetap duduk menunggu.
      return sudahSiap
        ? {
            judul: 'Siap diantar!',
            isi: 'Pesananmu sudah selesai dibuat.',
            rasa: 'berhasil',
          }
        : {
            judul: 'Pembayaran Berhasil!',
            isi: 'Pesananmu sedang dibuat. Tunggu di meja ya.',
            rasa: 'berhasil',
          }
    case 'cancelled':
    case 'canceled':
      return {
        judul: 'Pesanan dibatalkan',
        isi: 'Silakan pesan ulang atau tanya kasir.',
        rasa: 'gagal',
      }
    case 'expired':
      return {
        judul: 'Pesanan kedaluwarsa',
        isi: 'Batas waktu pembayaran lewat. Silakan pesan ulang.',
        rasa: 'gagal',
      }
    default:
      return { judul: status, isi: 'Tanya kasir kalau statusnya tak berubah.', rasa: 'menunggu' }
  }
}

/**
 * Apakah pesanan ini masih "hidup" untuk ditampilkan di popup "Pesanan saya".
 *
 * Yang masih hidup: menunggu bayar (pending) atau sudah bayar tapi belum siap
 * diantar. Yang sudah siap, dibatalkan, atau kedaluwarsa dianggap SELESAI —
 * menampilkannya berarti membawa pesanan pelanggan sebelumnya ke pelanggan
 * berikutnya di meja yang sama. Status tak dikenal dianggap masih hidup
 * (jangan mengarang kesimpulan buruk dari yang tak kita kenal).
 */
export function pesananMasihAktif(status: string, readyAt: string | null): boolean {
  const s = status.toLowerCase()
  if (s === 'cancelled' || s === 'canceled' || s === 'expired') return false
  return readyAt === null
}

/**
 * Tampilan per rasa.
 *
 * Warna sepenuh kartu, bukan sekadar titik kecil di sudut: perubahan status
 * terjadi justru saat HP tergeletak di meja, jadi yang harus bekerja adalah
 * apa yang terlihat sekilas dari jarak sejengkal — bukan detail yang baru
 * kelihatan kalau sudah dicari.
 */
export const TAMPILAN: Record<Rasa, { kotak: string; lingkaran: string; ikon: string }> = {
  menunggu: {
    kotak: 'border-slate-200',
    lingkaran: 'bg-slate-100 text-slate-500',
    ikon: '⋯',
  },
  berhasil: {
    kotak: 'border-emerald-300 bg-emerald-50 text-emerald-950',
    lingkaran: 'bg-emerald-600 text-white',
    ikon: '✓',
  },
  gagal: {
    kotak: 'border-rose-300 bg-rose-50 text-rose-950',
    lingkaran: 'bg-rose-600 text-white',
    ikon: '✕',
  },
}
