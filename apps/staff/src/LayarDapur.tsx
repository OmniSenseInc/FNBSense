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
 *
 * WebSocket KDS (F3b) sengaja belum dipakai di sini. Ia cuma mengabari pesanan
 * BARU — layar yang baru dibuka tetap butuh muat awal, jadi ia tambahan, bukan
 * pengganti. Dan ia menuntut satu proses lagi yang harus ingat dinyalakan;
 * kalau lupa, dapur diam tanpa tanda apa pun. Polling tak punya moda gagal itu.
 */
const JEDA_MS = 5000

/**
 * Layar dapur: pesanan yang sudah dibayar tapi belum diserahkan.
 *
 * Layar inilah yang mengisi `ready_at`, dan `ready_at` itulah yang menyalakan
 * titik ketiga di HP pelanggan ("Siap diantar"). Tanpa layar ini, kabar untuk
 * pelanggan berhenti tepat setelah pembayarannya masuk — persis di titik dia
 * mulai benar-benar menunggu.
 *
 * Dibaca dari jarak ~2 meter oleh orang yang tangannya basah atau berlumur
 * tepung, jadi ukuran hurufnya diatur untuk itu, bukan untuk HP di genggaman.
 *
 * Nol tombol uang. Akunnya memang sama dengan kasir (yang meracik dan yang
 * menerima uang biasanya orang yang sama), jadi pemisahan ini kebiasaan, bukan
 * pagar — pagarnya tetap `role:cashier,owner` di server. Yang dijaga di sini
 * cuma satu: tak ada tombol terminal (bayar/batal) yang bisa tersenggol tangan
 * yang sedang sibuk.
 */
export default function LayarDapur({ onKeluar }: { onKeluar: () => void }) {
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
    <div className="min-h-svh bg-stone-50 text-stone-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3">
        <Link to="/" className="rounded-md border border-stone-300 px-3 py-2 text-sm">
          ← Antrean
        </Link>
        <div className="text-right">
          <h1 className="text-xl font-semibold">Dapur</h1>
          <p className="text-sm text-stone-600">
            {daftar === null ? 'Memuat…' : `${daftar.length} pesanan`}
          </p>
        </div>
      </header>

      {/* Lebih lebar dari layar kasir dan berkolom: tablet dapur dipasang
          mendatar, dan yang mahal di dapur bukan ruang layar melainkan menggulir
          dengan tangan berlumur tepung. Beberapa pesanan pertama harus terlihat
          sekaligus. */}
      <main className="mx-auto max-w-6xl px-4 py-4">
        {galat && (
          <p role="alert" className="mb-4 rounded-md bg-red-50 px-3 py-2 text-base text-red-700">
            {galat}
          </p>
        )}

        {daftar !== null && daftar.length === 0 && (
          <p className="py-16 text-center text-lg text-stone-600">
            Tak ada pesanan yang menunggu dibuat.
          </p>
        )}

        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {daftar?.map((pesanan) => {
            const meja = labelMeja(pesanan.meja, pesanan.tipe)

            return (
              <li key={pesanan.id} className="rounded-md border border-stone-200 bg-white p-4">
                <div className="flex items-baseline justify-between gap-3">
                  <p className="text-3xl font-bold tabular-nums">{pesanan.order_number}</p>
                  {/* Jam BAYAR, bukan jam pesan: hitungan menunggu pelanggan di
                      layar ini mulai sejak uangnya diterima. */}
                  <p className="text-sm text-stone-600">dibayar {jam(pesanan.waktuBayar)}</p>
                </div>
                {/* Meja dapat barisnya sendiri dan ikut dibesarkan: inilah
                    satu-satunya petunjuk ke mana minumannya diantar, dan di
                    kartu kasir ia sempat menumpang di belakang nomor. */}
                <p className="text-xl">
                  {meja && <span className="font-semibold">{meja}</span>}
                  {meja && <span className="text-stone-400"> · </span>}
                  <span className="text-stone-600">{pesanan.customer_name}</span>
                </p>

                {/* Isi pesanan ikut ditampilkan, bukan cuma nomornya: daftar ini
                    adalah lembar kerja orang yang meracik. */}
                <ul className="mt-3 flex flex-col gap-2">
                  {pesanan.items.map((item, i) => (
                    // Kunci pakai indeks: satu pesanan bisa memuat produk sama
                    // dua baris dengan catatan berbeda, dan daftar ini tak pernah
                    // diurut ulang.
                    <li key={i} className="text-xl">
                      <span className="text-2xl font-bold tabular-nums">{item.qty}×</span>{' '}
                      {item.nama}
                      {/* Catatan dijadikan blok berwarna, bukan baris abu-abu
                          seperti di kartu kasir. Nama produk yang salah baca
                          masih ketahuan saat menuang; "tanpa gula" yang terlewat
                          baru ketahuan setelah gelasnya sampai ke meja dan harus
                          dibuang. */}
                      {item.note && (
                        <span className="mt-1 block rounded bg-sage-50 px-2 py-1 text-lg font-semibold text-sage-800">
                          {item.note}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>

                {/* Satu sentuhan, tanpa konfirmasi kedua — beda dari tombol
                    bayar dan batal. Menandai siap tidak terminal: salah tekan
                    paling jauh membuat pelanggan datang sedikit terlalu cepat,
                    dan penandaan ulang tak menggeser apa pun. Konfirmasi di
                    sini cuma akan melatih tangan menekan dua kali tanpa membaca. */}
                <button
                  type="button"
                  disabled={kirimId !== null}
                  onClick={() => siap(pesanan)}
                  className="mt-4 w-full rounded-md bg-stone-900 px-4 py-4 text-xl font-semibold text-white disabled:opacity-50"
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
