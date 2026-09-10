import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ambilNotifikasi, SESI_HABIS, tandaiSemuaDibaca, type Notifikasi } from './api'
import { tanggalJam } from './format'

/**
 * Warna per tingkat kegentingan.
 *
 * Tingkat yang belum dikenal jatuh ke netral, bukan ke merah: nilainya lahir
 * di service lain, dan menambah satu tingkat baru di sana tak boleh membuat
 * seluruh inbox kasir terlihat seperti keadaan darurat.
 */
const WARNA: Record<string, string> = {
  critical: 'border-red-300 bg-red-50',
  warning: 'border-sage-300 bg-sage-50',
  info: 'border-stone-200 bg-white',
}

/**
 * Inbox peringatan stok.
 *
 * Halaman sendiri, bukan panel yang menggantung di antrean: semua tujuan lain
 * di app ini sudah berupa rute, dan di layar HP daftar selebar penuh jauh
 * lebih terbaca daripada kotak sempit.
 *
 * "Dibaca" TIDAK terjadi otomatis saat halaman dibuka. Kasir yang membuka
 * daftar karena penasaran tak boleh menghapus penanda untuk peringatan stok
 * yang belum ditindaklanjuti siapa pun — yang menghapusnya harus tindakan
 * sadar, dan itulah tombol di bawah.
 */
export default function LayarNotifikasi({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Notifikasi[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false

    ambilNotifikasi()
      .then((hasil) => {
        if (batal) return
        setDaftar(hasil)
        setGalat(null)
      })
      .catch((err: unknown) => {
        if (batal) return
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal memuat notifikasi.')
      })

    return () => {
      batal = true
    }
  }, [])

  const belum = daftar?.filter((n) => !n.sudahDibaca).length ?? 0

  async function tandai() {
    setSibuk(true)
    setGalat(null)

    try {
      await tandaiSemuaDibaca()
      // Ditandai di layar SETELAH server menerima, bukan sebelum. Kalau
      // dibalik dan permintaannya gagal, lonceng bersih di mata kasir padahal
      // peringatannya masih menunggu di server.
      setDaftar((lama) => (lama === null ? null : lama.map((n) => ({ ...n, sudahDibaca: true }))))
    } catch (err: unknown) {
      if (err instanceof Error && err.message === SESI_HABIS) {
        keluarRef.current()
        return
      }
      setGalat(err instanceof Error ? err.message : 'Gagal menandai.')
    } finally {
      setSibuk(false)
    }
  }

  return (
    <div className="min-h-svh bg-stone-50 text-stone-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Pemberitahuan</h1>
          <p className="text-sm text-stone-600">
            {daftar === null
              ? 'Memuat…'
              : belum > 0
                ? `${belum} belum dibaca`
                : 'Semua sudah dibaca'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {belum > 0 && (
            <button
              type="button"
              onClick={tandai}
              disabled={sibuk}
              className="rounded-md border border-stone-300 px-3 py-2 text-sm disabled:opacity-50"
            >
              {sibuk ? 'Menandai…' : 'Tandai semua dibaca'}
            </button>
          )}
          <Link to="/" className="rounded-md border border-stone-300 px-3 py-2 text-sm">
            ← Antrean
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-4 py-4">
        {galat && (
          <p role="alert" className="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm">
            {galat}
          </p>
        )}

        {daftar === null && !galat && <p className="text-sm text-stone-600">Memuat…</p>}

        {daftar !== null && daftar.length === 0 && (
          <p className="py-16 text-center text-sm text-stone-600">
            Belum ada pemberitahuan. Peringatan stok akan muncul di sini.
          </p>
        )}

        <ul className="space-y-2">
          {daftar?.map((n) => (
            <li
              key={n.id}
              className={`rounded-md border p-3 ${WARNA[n.tingkat] ?? WARNA.info} ${
                n.sudahDibaca ? 'opacity-60' : ''
              }`}
            >
              <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-semibold">{n.judul}</p>
                {/* Titik biru, bukan tulisan "BARU": ia harus terbaca dalam
                    sekali lirik dari jarak satu meter di meja kasir. */}
                {!n.sudahDibaca && (
                  <span
                    aria-label="Belum dibaca"
                    className="mt-1 size-2 shrink-0 rounded-full bg-blue-600"
                  />
                )}
              </div>
              <p className="mt-1 text-sm text-stone-700">{n.isi}</p>
              <p className="mt-1 text-xs text-stone-500 tabular-nums">{tanggalJam(n.waktu)}</p>
            </li>
          ))}
        </ul>
      </main>
    </div>
  )
}
