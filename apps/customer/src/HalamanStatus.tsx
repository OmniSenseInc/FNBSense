import { useEffect, useState } from 'react'
import { useParams } from 'react-router'
import { ambilPesanan, type Pesanan } from './api'
import { rupiah } from './format'

/** Jeda polling. 5 detik: cukup cepat terasa hidup, cukup jarang untuk hemat baterai. */
const JEDA_MS = 5000

/** Satu baris rincian harga. Nilai negatif (promo) ditulis apa adanya. */
function Baris({ label, nilai }: { label: string; nilai: number }) {
  return (
    <div className="flex items-center justify-between py-1">
      <p className="text-sm text-slate-600">{label}</p>
      {/* Minus ditaruh di depan "Rp", bukan di depan angka: "Rp -5.000" terbaca
          seperti salah ketik, "−Rp 5.000" terbaca sebagai potongan. */}
      <p className="text-sm tabular-nums">
        {nilai < 0 ? `−${rupiah(-nilai)}` : rupiah(nilai)}
      </p>
    </div>
  )
}

/**
 * Terjemahan status server -> kalimat yang berarti bagi pelanggan.
 *
 * Sengaja TIDAK menampilkan kode mentah seperti "PENDING": pelanggan tak perlu
 * tahu kosakata internal kita. Status tak dikenal jatuh ke teks apa adanya
 * supaya status baru di server tak bikin layar kosong.
 */
function jelaskanStatus(status: string, adaQris: boolean): { judul: string; isi: string } {
  switch (status.toLowerCase()) {
    case 'pending':
      return {
        judul: 'Menunggu pembayaran',
        // Kalimatnya ikut QR-nya. Menyuruh "bayar di meja kasir" padahal
        // QR-nya terpampang di layar membuat pelanggan bangkit tanpa perlu —
        // persis kebalikan dari alasan fitur ini ada.
        isi: adaQris
          ? 'Bayar dengan QRIS berikut. Status akan berubah setelah kasir memastikan pembayaranmu masuk.'
          : 'Bayar di meja kasir. Status akan berubah setelah kasir memastikan pembayaranmu masuk.',
      }
    case 'paid':
      return {
        judul: 'Pembayaran Berhasil!',
        isi: 'Pesananmu sedang dibuat. Tunggu di meja ya.',
      }
    case 'cancelled':
    case 'canceled':
      return { judul: 'Pesanan dibatalkan', isi: 'Silakan pesan ulang atau tanya kasir.' }
    case 'expired':
      return {
        judul: 'Pesanan kedaluwarsa',
        isi: 'Batas waktu pembayaran lewat. Silakan pesan ulang.',
      }
    default:
      return { judul: status, isi: 'Tanya kasir kalau statusnya tak berubah.' }
  }
}

export default function HalamanStatus() {
  const { id } = useParams<{ id: string }>()
  const [pesanan, setPesanan] = useState<Pesanan | null>(null)
  const [galat, setGalat] = useState(false)

  // Alamat gambar yang GAGAL dimuat, bukan sekadar penanda boolean. Bedanya
  // nyata: kalau owner memperbaiki QRIS-nya, alamatnya berubah, dan blok
  // pembayaran hidup lagi sendiri pada polling berikutnya — tanpa pelanggan
  // perlu me-refresh, dan tanpa effect tambahan untuk mengatur ulang.
  const [urlGagal, setUrlGagal] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return

    let batal = false
    let timer: number

    const periksa = async () => {
      try {
        const p = await ambilPesanan(id)
        if (batal) return
        setPesanan(p)
        setGalat(false)
        // Berhenti bertanya begitu statusnya final — polling selamanya
        // menghabiskan baterai pelanggan tanpa hasil.
        if (p.status.toLowerCase() === 'pending') {
          timer = window.setTimeout(periksa, JEDA_MS)
        }
      } catch {
        if (batal) return
        setGalat(true)
        // Gagal sekali bukan berarti gagal selamanya (sinyal kafe naik-turun),
        // jadi tetap coba lagi — pelanggan tak perlu me-refresh manual.
        timer = window.setTimeout(periksa, JEDA_MS)
      }
    }

    periksa()

    return () => {
      batal = true
      window.clearTimeout(timer)
    }
  }, [id])

  if (!pesanan) {
    return (
      <div className="mx-auto max-w-md px-4 py-16 text-center">
        <p className="text-base font-semibold">
          {galat ? 'Gagal memuat status' : 'Memuat status…'}
        </p>
        <p className="mt-1 text-sm text-slate-600">
          {galat ? 'Mencoba lagi otomatis. Kalau lama, tanya kasir.' : 'Sebentar ya.'}
        </p>
      </div>
    )
  }

  // Server sudah menyaring: qrisImageUrl hanya terisi untuk pesanan yang masih
  // menunggu bayar. Yang tersisa di sini cuma kasus gambarnya sendiri rusak.
  const qris = pesanan.qrisImageUrl !== urlGagal ? pesanan.qrisImageUrl : null
  const { judul, isi } = jelaskanStatus(pesanan.status, qris !== null)

  return (
    <div className="min-h-svh bg-white text-slate-900">
      <main className="mx-auto max-w-md px-4 py-8">
        <p className="text-sm text-slate-600">Nomor pesanan</p>
        {/* Nomor dibuat besar: ini yang disebut kasir saat memanggil. */}
        <p className="text-2xl font-semibold tabular-nums">{pesanan.order_number}</p>

        <div className="mt-6 rounded-md border border-slate-200 p-4">
          <p className="text-base font-semibold">{judul}</p>
          <p className="mt-1 text-sm text-slate-600">{isi}</p>
        </div>

        {/* Blok bayar ditaruh SEBELUM rincian: yang dicari pelanggan saat
            membuka halaman ini adalah cara membayar, dan kejutan angkanya
            sudah dijinakkan di layar ringkasan sebelumnya. */}
        {qris && (
          <div className="mt-6 flex flex-col items-center gap-3 rounded-md border border-slate-200 p-4 text-center">
            <div>
              <p className="text-sm text-slate-600">Bayar sejumlah</p>
              <p className="text-2xl font-semibold tabular-nums">
                {rupiah(pesanan.grand_total)}
              </p>
            </div>

            {/* Latar putih dipaksa, bukan diwarisi: pemindai butuh kontras
                gelap-di-terang, dan QRIS yang diunggah owner bisa saja PNG
                transparan yang jadi tak terbaca di atas latar apa pun. */}
            <div className="rounded-lg border border-slate-200 bg-white p-3">
              <img
                src={qris}
                alt="Kode QRIS untuk membayar pesanan ini"
                // Alamat rusak atau gambar hilang tak boleh meninggalkan ikon
                // patah di layar orang yang sedang membayar — seluruh bloknya
                // menghilang dan kalimat status kembali menyuruh ke kasir.
                onError={() => setUrlGagal(pesanan.qrisImageUrl)}
                className="block h-44 w-44 object-contain"
              />
            </div>

            {/* Langkah-langkahnya ditulis karena kamera HP tak bisa memotret
                layarnya sendiri. Tanpa petunjuk ini pelanggan menatap QR-nya
                lalu tetap berjalan ke kasir. */}
            <ol className="w-full list-inside list-decimal text-left text-sm text-slate-600">
              <li>Tekan dan tahan gambar di atas, lalu simpan</li>
              <li>Buka aplikasi bank atau e-wallet-mu</li>
              <li>Pilih Scan QR, lalu ambil dari galeri</li>
            </ol>
          </div>
        )}

        {/* Rincian penuh, bukan cuma total: inilah tempat pelanggan melihat
            kenapa angkanya beda dari subtotal menu tadi. Baris bernilai nol
            disembunyikan supaya kafe tanpa pajak/layanan tak melihat deretan
            "Rp 0" yang cuma jadi kebisingan. */}
        <div className="mt-6 border-t border-slate-200 pt-4">
          <Baris label="Subtotal menu" nilai={pesanan.gross_subtotal} />
          {pesanan.discount_total > 0 && (
            <Baris label="Promo" nilai={-pesanan.discount_total} />
          )}
          {/* Layanan & pajak SELALU ditampilkan, walau Rp 0. Pelanggan yang
              curiga ada biaya tersembunyi jadi punya jawaban tertulis, bukan
              cuma ketiadaan baris yang bisa berarti apa saja. */}
          <Baris label="Biaya layanan" nilai={pesanan.service_charge} />
          <Baris label="Pajak" nilai={pesanan.tax} />

          <div className="mt-3 flex items-center justify-between border-t border-slate-200 pt-3">
            <p className="text-base font-semibold">Total dibayar</p>
            <p className="text-[17px] font-semibold tabular-nums">
              {rupiah(pesanan.grand_total)}
            </p>
          </div>
        </div>

        <p className="mt-6 text-sm text-slate-600">
          Atas nama {pesanan.customer_name}. Simpan halaman ini sampai pesanan datang.
        </p>

        {galat && (
          <p className="mt-4 text-sm text-slate-600">
            Koneksi sempat terputus — status di atas mungkin belum yang terbaru.
          </p>
        )}
      </main>
    </div>
  )
}
