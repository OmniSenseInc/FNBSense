import { useRef, useState } from 'react'
import { Link } from 'react-router'
import { gantiSandi, SESI_HABIS } from './api'

/**
 * Ganti sandi sendiri — hak SEMUA peran, bukan wewenang owner.
 *
 * Rute sendiri, bukan menumpang /staf, justru karena itu: /staf owner-only,
 * sedangkan kasir yang diberi sandi awal oleh owner harus punya cara
 * mempensiunkannya. Selama layar ini tak ada, sandi buatan owner berlaku
 * selamanya dan owner selamanya tahu sandi setiap kasirnya.
 */
export default function LayarSandi({ onKeluar }: { onKeluar: () => void }) {
  const [lama, setLama] = useState('')
  const [baru, setBaru] = useState('')
  const [ulangi, setUlangi] = useState('')
  const [sibuk, setSibuk] = useState(false)
  const [galat, setGalat] = useState<string | null>(null)
  const [berhasil, setBerhasil] = useState(false)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    setBerhasil(false)

    if (lama === '' || baru === '') {
      setGalat('Sandi lama dan sandi baru wajib diisi.')
      return
    }
    // Diperiksa di sini juga, bukan cuma di server: kalau menunggu 422, orangnya
    // sudah menekan tombol dan kehilangan apa yang diketik di kotak ketiga.
    if (baru !== ulangi) {
      setGalat('Ketikan ulang sandi baru belum sama.')
      return
    }

    setSibuk(true)
    setGalat(null)
    gantiSandi(lama, baru)
      .then(() => {
        setBerhasil(true)
        setLama('')
        setBaru('')
        setUlangi('')
      })
      .catch((err: unknown) => {
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal mengganti sandi. Coba lagi.')
      })
      .finally(() => setSibuk(false))
  }

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <h1 className="text-base font-semibold">Ganti sandi</h1>
        <Link to="/" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
          ← Antrean
        </Link>
      </header>

      <main className="mx-auto max-w-md px-4 py-4">
        {galat && (
          <p role="alert" className="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm">
            {galat}
          </p>
        )}
        {berhasil && (
          <p
            role="status"
            className="mb-3 rounded-md border border-green-300 bg-green-50 p-3 text-sm"
          >
            Sandi berhasil diganti. Pakai sandi baru saat masuk berikutnya.
          </p>
        )}

        <form
          onSubmit={submit}
          className="space-y-2 rounded-md border border-slate-200 bg-white p-4"
        >
          <label htmlFor="sandi-lama" className="block text-sm font-semibold">
            Sandi sekarang
          </label>
          <input
            id="sandi-lama"
            type="password"
            autoComplete="current-password"
            value={lama}
            onChange={(e) => setLama(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />

          <label htmlFor="sandi-baru" className="block pt-2 text-sm font-semibold">
            Sandi baru
          </label>
          <input
            id="sandi-baru"
            type="password"
            autoComplete="new-password"
            value={baru}
            onChange={(e) => setBaru(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />

          <label htmlFor="sandi-ulangi" className="block pt-2 text-sm font-semibold">
            Ketik ulang sandi baru
          </label>
          <input
            id="sandi-ulangi"
            type="password"
            autoComplete="new-password"
            value={ulangi}
            onChange={(e) => setUlangi(e.target.value)}
            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
          />

          <p className="pt-1 text-xs text-slate-600">
            Minimal 8 karakter, mengandung huruf besar, huruf kecil, dan angka.
          </p>

          <button
            type="submit"
            disabled={sibuk}
            className="mt-2 w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
          >
            {sibuk ? 'Menyimpan…' : 'Ganti sandi'}
          </button>
        </form>

        {/* Disebut apa adanya. Orang yang mengganti sandi karena curiga akunnya
            dipakai orang lain berhak tahu bahwa sesi yang terlanjur terbuka tak
            langsung terputus — kalau tidak, dia mengira sudah aman padahal
            belum. */}
        <p className="mt-3 text-xs text-slate-600">
          Perangkat lain yang sedang terbuka tidak langsung terputus; sesinya berakhir sendiri
          paling lama 15 menit. Kalau kamu curiga akunmu dipakai orang lain, tunggu sampai jendela
          itu lewat.
        </p>
      </main>
    </div>
  )
}
