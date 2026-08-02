import { useEffect, useRef, useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { Link } from 'react-router'
import {
  ambilMeja,
  buatMeja,
  putarQr,
  SESI_HABIS,
  ubahMeja,
  urlMeja,
  type Meja,
} from './api'

/** Baris mana yang sedang membuka langkah kedua, dan langkah kedua yang mana. */
type Aksi = { id: string; mode: 'nama' | 'putar' }

/**
 * Meja & QR — owner saja.
 *
 * Tanpa layar ini outlet tak punya pintu masuk sama sekali: tak ada meja, tak
 * ada qr_token, tak ada yang bisa ditempel. Setelan outlet yang sudah rapi pun
 * tak berguna kalau tak seorang pun bisa memesan.
 *
 * TANPA tombol hapus, sengaja. `orders.table_id` memakai nullOnDelete, jadi
 * menghapus meja tidak gagal — ia diam-diam mencabut meja dari seluruh riwayat
 * pesanannya, dan nota yang dicetak ulang berbulan-bulan kemudian tak lagi
 * menyebut meja mana. Yang sebenarnya dimaui owner ("meja ini sudah tidak
 * dipakai") dilayani saklar aktif: QR-nya mati, riwayatnya utuh.
 */
export default function LayarMeja({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Meja[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [labelBaru, setLabelBaru] = useState('')
  const [ubahNama, setUbahNama] = useState('')
  const [aksi, setAksi] = useState<Aksi | null>(null)
  const [sibuk, setSibuk] = useState(false)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  function tangani(err: unknown, cadangan: string) {
    if (err instanceof Error && err.message === SESI_HABIS) {
      keluarRef.current()
      return
    }
    setGalat(err instanceof Error ? err.message : cadangan)
  }

  useEffect(() => {
    let batal = false

    ambilMeja()
      .then((hasil) => {
        if (batal) return
        setDaftar(hasil)
        setGalat(null)
      })
      .catch((err: unknown) => {
        if (batal) return
        tangani(err, 'Gagal memuat daftar meja.')
      })

    return () => {
      batal = true
    }
  }, [])

  /** Satu tempat menyisipkan meja hasil server, tetap terurut seperti daftarnya. */
  function perbarui(meja: Meja) {
    setDaftar((lama) => {
      const tanpa = (lama ?? []).filter((m) => m.id !== meja.id)

      return [...tanpa, meja].sort((a, b) => a.label.localeCompare(b.label))
    })
  }

  async function tambah(e: React.FormEvent) {
    e.preventDefault()
    if (sibuk) return

    const label = labelBaru.trim()
    // Diperiksa dari daftar yang sudah di tangan: server memang menolak
    // duplikat, tapi kalimatnya bahasa Inggris dan datang setelah satu putaran
    // jaringan. Yang menegakkan tetap server — ini cuma jalan pintas menuju
    // jawaban yang sudah pasti.
    if ((daftar ?? []).some((m) => m.label.toLowerCase() === label.toLowerCase())) {
      setGalat(`Sudah ada meja bernama "${label}".`)
      return
    }
    // Diperiksa dari daftar yang sudah di tangan: server memang menolak
    // duplikat, tapi kalimatnya bahasa Inggris dan datang setelah satu putaran
    // jaringan. Yang menegakkan tetap server — ini cuma jalan pintas menuju
    // jawaban yang sudah pasti.

    setSibuk(true)
    setGalat(null)
    try {
      perbarui(await buatMeja(label))
      setLabelBaru('')
    } catch (err) {
      tangani(err, 'Gagal menambah meja. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  async function simpanNama(meja: Meja) {
    if (sibuk) return

    const label = ubahNama.trim()
    if (label === '' || label === meja.label) {
      setAksi(null)
      return
    }

    setSibuk(true)
    setGalat(null)
    try {
      perbarui(await ubahMeja(meja.id, { label }))
      setAksi(null)
    } catch (err) {
      tangani(err, 'Gagal mengubah nama. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  async function setAktif(meja: Meja, aktif: boolean) {
    if (sibuk) return

    setSibuk(true)
    setGalat(null)
    try {
      perbarui(await ubahMeja(meja.id, { aktif }))
    } catch (err) {
      tangani(err, 'Gagal mengubah status meja. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  async function ganti(meja: Meja) {
    if (sibuk) return

    setSibuk(true)
    setGalat(null)
    try {
      perbarui(await putarQr(meja.id))
      setAksi(null)
    } catch (err) {
      tangani(err, 'Gagal menerbitkan QR baru. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  // Diperiksa sekali, bukan per baris: kalau alamat app pelanggan belum disetel,
  // SEMUA QR di halaman ini sama-sama tak bisa digambar.
  const contoh = daftar?.[0]
  const alamatSiap = contoh === undefined || urlMeja(contoh.qrToken) !== null

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 print:hidden">
        <Link to="/" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
          ← Antrean
        </Link>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => window.print()}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
          >
            Cetak QR
          </button>
          <h1 className="text-base font-semibold">Meja &amp; QR</h1>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-4 py-4">
        {galat && (
          <p
            role="alert"
            className="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 print:hidden"
          >
            {galat}
          </p>
        )}

        {!alamatSiap && (
          // Menolak menggambar lebih baik daripada menggambar yang salah:
          // stiker QR dicetak lalu ditempel, dan yang keliru baru ketahuan dari
          // pelanggan yang gagal memesan.
          <p className="mb-4 rounded-md bg-amber-100 px-3 py-2 text-sm text-amber-900">
            Alamat app pelanggan belum disetel (<code>VITE_CUSTOMER_URL</code>), jadi QR belum
            bisa digambar. Isi dulu, lalu muat ulang halaman ini.
          </p>
        )}

        <form onSubmit={tambah} className="mb-4 flex gap-2 print:hidden">
          <input
            aria-label="Nama meja baru"
            required
            maxLength={50}
            placeholder="Meja 1"
            value={labelBaru}
            onChange={(e) => setLabelBaru(e.target.value)}
            className="grow rounded-md border border-slate-300 px-3 py-2 text-base"
          />
          <button
            type="submit"
            disabled={sibuk}
            className="rounded-md bg-slate-900 px-4 py-2 text-base font-semibold text-white disabled:opacity-50"
          >
            Tambah
          </button>
        </form>

        {daftar !== null && daftar.length === 0 && (
          <p className="py-16 text-center text-sm text-slate-600">
            Belum ada meja. Tambahkan satu, lalu cetak QR-nya dan tempel di mejanya.
          </p>
        )}

        <ul className="flex flex-col gap-3">
          {daftar?.map((meja) => {
            const alamat = urlMeja(meja.qrToken)

            return (
              <li
                key={meja.id}
                // Meja nonaktif tak ikut tercetak: QR-nya sudah ditolak server,
                // jadi mencetaknya cuma menghasilkan stiker yang tak berfungsi.
                className={`rounded-md border border-slate-200 bg-white p-4 print:break-after-page print:border-0 ${
                  meja.aktif ? '' : 'print:hidden'
                }`}
              >
                <div className="flex items-start justify-between gap-3">
                  {aksi?.id === meja.id && aksi.mode === 'nama' ? (
                    <div className="flex grow gap-2 print:hidden">
                      <input
                        aria-label={`Nama baru untuk ${meja.label}`}
                        maxLength={50}
                        value={ubahNama}
                        onChange={(e) => setUbahNama(e.target.value)}
                        className="grow rounded-md border border-slate-300 px-3 py-2 text-base"
                      />
                      <button
                        type="button"
                        disabled={sibuk}
                        onClick={() => simpanNama(meja)}
                        className="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                      >
                        Simpan
                      </button>
                      <button
                        type="button"
                        onClick={() => setAksi(null)}
                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                      >
                        Batal
                      </button>
                    </div>
                  ) : (
                    <p className="text-lg font-semibold print:text-3xl">
                      {meja.label}
                      {!meja.aktif && (
                        <span className="ml-2 rounded-md bg-slate-100 px-2 py-1 text-sm font-normal text-slate-600 print:hidden">
                          Nonaktif
                        </span>
                      )}
                    </p>
                  )}
                </div>

                {alamat !== null && (
                  <div className="mt-3 flex flex-col items-center gap-2">
                    <QRCodeSVG
                      value={alamat}
                      size={160}
                      // Koreksi galat tingkat menengah: stiker di meja kafe kena
                      // tumpahan dan goresan, dan QR yang tak terbaca berarti
                      // pelanggan memanggil pelayan — persis yang dihindari.
                      level="M"
                      className="print:h-64 print:w-64"
                      title={`QR ${meja.label}`}
                    />
                    {/* Alamatnya ikut ditulis kecil: kalau kamera pelanggan
                        bermasalah, mengetik masih mungkin. */}
                    <span className="break-all text-center text-sm text-slate-500">{alamat}</span>
                  </div>
                )}

                <div className="mt-3 flex flex-wrap gap-2 print:hidden">
                  <button
                    type="button"
                    disabled={sibuk}
                    onClick={() => {
                      setUbahNama(meja.label)
                      setAksi({ id: meja.id, mode: 'nama' })
                    }}
                    className="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
                  >
                    Ubah nama
                  </button>

                  <button
                    type="button"
                    disabled={sibuk}
                    onClick={() => setAktif(meja, !meja.aktif)}
                    className="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
                  >
                    {meja.aktif ? 'Nonaktifkan' : 'Aktifkan'}
                  </button>

                  {aksi?.id === meja.id && aksi.mode === 'putar' ? (
                    <div className="w-full">
                      {/* Dua langkah, dan kalimatnya menyebut akibat fisiknya:
                          stiker yang sudah tertempel di meja jadi sampah
                          seketika, dan itu tak bisa diurungkan dari mana pun. */}
                      <p role="alert" className="text-sm font-semibold text-red-700">
                        Terbitkan QR baru untuk {meja.label}? Stiker lama langsung mati dan harus
                        dicetak ulang.
                      </p>
                      <div className="mt-2 flex gap-2">
                        <button
                          type="button"
                          disabled={sibuk}
                          onClick={() => ganti(meja)}
                          className="rounded-md bg-red-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                          Ya, ganti QR
                        </button>
                        <button
                          type="button"
                          onClick={() => setAksi(null)}
                          className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                        >
                          Batal
                        </button>
                      </div>
                    </div>
                  ) : (
                    <button
                      type="button"
                      disabled={sibuk}
                      onClick={() => setAksi({ id: meja.id, mode: 'putar' })}
                      className="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
                    >
                      Ganti QR
                    </button>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      </main>
    </div>
  )
}
