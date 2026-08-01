import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { sedangDibuat } from './antrean'
import { ambilRiwayat, SESI_HABIS, tandaiSiap, type Pesanan } from './api'
import { jam, labelMeja } from './format'

/**
 * Jeda polling, sama dengan antrean.
 *
 * Layar ini dipolling justru karena isinya datang sendiri: tiap pesanan yang
 * dikonfirmasi kasir muncul di sini beberapa detik kemudian. Riwayat tak
 * dipolling karena ia tempat yang DIDATANGI untuk mencari satu hal tertentu.
 */
const JEDA_MS = 5000

/**
 * Pesanan yang sudah dibayar tapi belum diserahkan.
 *
 * Layar inilah yang mengisi `ready_at`, dan `ready_at` itulah yang menyalakan
 * titik ketiga di HP pelanggan ("Siap diantar"). Tanpa layar ini, kabar untuk
 * pelanggan berhenti tepat setelah pembayarannya masuk — persis di titik dia
 * mulai benar-benar menunggu.
 *
 * Kasir yang menekannya, bukan dapur — untuk sekarang. Kolom dan endpoint-nya
 * tak menyebut siapa, jadi layar KDS nanti tinggal memakai yang sama tanpa
 * mengubah apa pun di server.
 */
export default function LayarDibuat({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Pesanan[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  /** Baris yang penandaannya sedang jalan — kunci anti klik ganda. */
  const [kirimId, setKirimId] = useState<string | null>(null)
  /** Dinaikkan untuk memaksa muat ulang segera, tanpa menunggu jeda 5 detik. */
  const [versi, setVersi] = useState(0)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    let timer: number

    const muat = async () => {
      try {
        // Endpoint yang sama dengan riwayat: "dibayar hari ini" adalah himpunan
        // induk dari "sedang dibuat", dan yang memisahkan keduanya cuma
        // `ready_at`. Menambah jalur server untuk penyaringan yang bisa
        // dikerjakan dari daftar yang sudah di tangan cuma menambah tempat yang
        // harus sama-sama benar.
        const data = await ambilRiwayat()
        if (batal) return
        setDaftar(sedangDibuat(data))
        setGalat(null)
      } catch (err) {
        if (batal) return
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        // Daftar lama tetap ditampilkan — Wi-Fi kafe naik-turun, dan layar
        // kosong lebih menyesatkan daripada daftar yang mungkin basi.
        setGalat(err instanceof Error ? err.message : 'Gagal memuat daftar.')
      }
      if (!batal) timer = window.setTimeout(muat, JEDA_MS)
    }

    muat()

    return () => {
      batal = true
      window.clearTimeout(timer)
    }
  }, [versi])

  async function siap(pesanan: Pesanan) {
    if (kirimId !== null) return

    setKirimId(pesanan.id)
    setGalat(null)
    try {
      await tandaiSiap(pesanan.id)
    } catch (err) {
      if (err instanceof Error && err.message === SESI_HABIS) {
        keluarRef.current()
        return
      }
      setGalat(err instanceof Error ? err.message : 'Gagal menandai siap. Coba lagi.')
    } finally {
      setKirimId(null)
      // Muat ulang segera, berhasil maupun gagal: kartu yang sudah ditandai
      // harus hilang saat itu juga, bukan bertahan lima detik lagi dan mengundang
      // sentuhan kedua. Kalau gagal, daftar segarlah yang menunjukkan apa yang
      // sebenarnya terjadi di server.
      setVersi((v) => v + 1)
    }
  }

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <Link to="/" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
          ← Antrean
        </Link>
        <div className="text-right">
          <h1 className="text-base font-semibold">Sedang dibuat</h1>
          <p className="text-sm text-slate-600">
            {daftar === null ? 'Memuat…' : `${daftar.length} pesanan`}
          </p>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-4 py-4">
        {galat && (
          <p role="alert" className="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {galat}
          </p>
        )}

        {daftar !== null && daftar.length === 0 && (
          <p className="py-16 text-center text-sm text-slate-600">
            Tak ada pesanan yang menunggu dibuat.
          </p>
        )}

        <ul className="flex flex-col gap-3">
          {daftar?.map((pesanan) => {
            const meja = labelMeja(pesanan.meja, pesanan.tipe)

            return (
              <li key={pesanan.id} className="rounded-md border border-slate-200 bg-white p-4">
                <div className="flex items-baseline justify-between gap-3">
                  <p className="text-lg font-semibold">
                    <span className="tabular-nums">{pesanan.order_number}</span>
                    {meja && <span className="ml-2 text-slate-600">· {meja}</span>}
                  </p>
                  {/* Jam BAYAR, bukan jam pesan: hitungan menunggu pelanggan di
                      layar ini mulai sejak uangnya diterima. */}
                  <p className="text-sm text-slate-600">dibayar {jam(pesanan.waktuBayar)}</p>
                </div>
                <p className="text-sm text-slate-600">{pesanan.customer_name}</p>

                {/* Isi pesanan ikut ditampilkan, bukan cuma nomornya: daftar ini
                    adalah lembar kerja orang yang meracik, dan catatan seperti
                    "tanpa gula" tak punya tempat lain selama layar dapur belum
                    ada. */}
                <ul className="mt-3 flex flex-col gap-1">
                  {pesanan.items.map((item, i) => (
                    // Kunci pakai indeks: satu pesanan bisa memuat produk sama
                    // dua baris dengan catatan berbeda, dan daftar ini tak pernah
                    // diurut ulang.
                    <li key={i} className="text-sm">
                      <span className="tabular-nums font-semibold">{item.qty}×</span> {item.nama}
                      {item.note && <span className="block text-slate-600">— {item.note}</span>}
                    </li>
                  ))}
                </ul>

                {/* Satu sentuhan, tanpa konfirmasi kedua — beda dari tombol
                    bayar dan batal. Menandai siap tidak terminal: salah tekan
                    paling jauh membuat pelanggan datang sedikit terlalu cepat,
                    dan penandaan ulang tak menggeser apa pun. Konfirmasi di
                    sini cuma akan melatih kasir menekan dua kali tanpa membaca. */}
                <button
                  type="button"
                  disabled={kirimId !== null}
                  onClick={() => siap(pesanan)}
                  className="mt-3 w-full rounded-md bg-slate-900 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
                >
                  {kirimId === pesanan.id ? 'Menyimpan…' : 'Siap diantar'}
                </button>
              </li>
            )
          })}
        </ul>
      </main>
    </div>
  )
}
