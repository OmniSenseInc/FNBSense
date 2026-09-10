import { useState } from 'react'
import { login } from './api'

/**
 * Layar login kasir.
 *
 * Yang SENGAJA tak ada: "ingat saya" (token sudah bertahan di localStorage),
 * lupa password (belum ada endpoint-nya di IAM), dan daftar akun (kasir dibuat
 * owner lewat /api/staff, bukan mendaftar sendiri).
 */
export default function LayarLogin({ onMasuk }: { onMasuk: () => void }) {
  const [email, setEmail] = useState('')
  const [sandi, setSandi] = useState('')
  const [galat, setGalat] = useState<string | null>(null)
  const [mengirim, setMengirim] = useState(false)

  async function kirim(e: React.FormEvent) {
    e.preventDefault()
    if (mengirim) return

    setMengirim(true)
    setGalat(null)
    try {
      await login(email.trim(), sandi)
      onMasuk()
    } catch (err) {
      setGalat(err instanceof Error ? err.message : 'Login gagal. Coba lagi sebentar.')
      setMengirim(false)
    }
  }

  return (
    <div className="min-h-svh bg-stone-50 text-stone-900">
      <main className="mx-auto max-w-sm px-4 py-16">
        <h1 className="text-xl font-bold">Login</h1>
        <p className="mt-1 text-sm text-stone-600">
          Masukkan akun yang sudah didaftarkan.
        </p>

        {/* form, bukan div + onClick: Enter di kolom sandi ikut mengirim, dan
            pengelola kata sandi browser baru mau menawarkan isian otomatis
            kalau ini benar-benar form. */}
        <form onSubmit={kirim} className="mt-8 flex flex-col gap-4">
          <label className="flex flex-col gap-1">
            <span className="text-sm text-stone-600">Email</span>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="username"
              required
              className="rounded-md border border-stone-300 px-3 py-2 text-base"
            />
          </label>

          <label className="flex flex-col gap-1">
            <span className="text-sm text-stone-600">Password</span>
            <input
              type="password"
              value={sandi}
              onChange={(e) => setSandi(e.target.value)}
              autoComplete="current-password"
              required
              className="rounded-md border border-stone-300 px-3 py-2 text-base"
            />
          </label>

          {galat && (
            // role="alert" supaya pembaca layar mengumumkannya; tanpa itu kasir
            // yang memakainya hanya melihat tombol berhenti bekerja.
            <p role="alert" className="text-sm text-red-700">
              {galat}
            </p>
          )}

          <button
            type="submit"
            disabled={mengirim}
            className="mt-2 rounded-md bg-stone-900 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
          >
            {mengirim ? 'Masuk…' : 'Masuk'}
          </button>
        </form>
      </main>
    </div>
  )
}
