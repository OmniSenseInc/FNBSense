import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ambilStaf, buatKasir, hapusStaf, resetSandiStaf, SESI_HABIS, type Staf, ubahAktifStaf } from './api'

/**
 * Karyawan: siapa yang boleh masuk, dan siapa yang sudah tidak.
 *
 * Pintu terakhir yang hilang. Backend-nya (`/api/staff` di IAM) sudah lengkap
 * sejak lama, tapi tanpa layar ini satu-satunya cara membuat akun kasir adalah
 * curl — artinya menyiapkan kafe baru mustahil dilakukan pemiliknya sendiri.
 *
 * Owner saja. Tak ada penjaga di sini, sepola /menu dan /bahan: penjaganya
 * `role:owner` di IAM, dan kasir yang memaksa alamat ini melihat pesan galat,
 * bukan daftar yang bisa diubahnya. Penjaga kedua di layar cuma membuat dua
 * tempat memutuskan hal yang sama, dan yang di sini paling mudah dibohongi.
 *
 * TIDAK dipolling: daftar karyawan berubah beberapa kali setahun.
 */
export default function LayarStaf({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Staf[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  const [versi, setVersi] = useState(0)

  const [nama, setNama] = useState('')
  const [email, setEmail] = useState('')
  const [sandi, setSandi] = useState('')

  /** Staf yang baris reset sandinya sedang terbuka. null = tak ada. */
  const [resetUntuk, setResetUntuk] = useState<string | null>(null)
  const [sandiBaru, setSandiBaru] = useState('')

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    setSibuk(true)

    ambilStaf()
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
        setGalat(err instanceof Error ? err.message : 'Gagal memuat karyawan.')
      })
      .finally(() => {
        if (!batal) setSibuk(false)
      })

    return () => {
      batal = true
    }
  }, [versi])

  const jalankan = (kerja: Promise<void>) => {
    setSibuk(true)
    setGalat(null)
    kerja
      .then(() => setVersi((v) => v + 1))
      .catch((err: unknown) => {
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal menyimpan. Coba lagi.')
        setSibuk(false)
      })
  }

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    const namaBersih = nama.trim()
    const emailBersih = email.trim()

    if (namaBersih === '' || emailBersih === '' || sandi === '') {
      setGalat('Nama, email, dan sandi wajib diisi.')
      return
    }

    // Sandi baru dikosongkan SETELAH server menerima, beda dari nama & email:
    // kalau ia ditolak (aturan panjang/campuran huruf), owner harus bisa
    // memperbaiki yang barusan diketik, bukan mengarang ulang dari nol.
    setNama('')
    setEmail('')
    jalankan(buatKasir(namaBersih, emailBersih, sandi).then(() => setSandi('')))
  }

  return (
    <div className="min-h-svh bg-stone-50 text-stone-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Karyawan</h1>
          <p className="text-sm text-stone-600">
            {daftar === null ? 'Memuat…' : `${daftar.length} akun`}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setVersi((v) => v + 1)}
            disabled={sibuk}
            className="rounded-md border border-stone-300 px-3 py-2 text-sm disabled:opacity-50"
          >
            {sibuk ? 'Memuat…' : 'Muat ulang'}
          </button>
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

        <section className="rounded-md border border-stone-200 bg-white p-4">
          <h2 className="text-sm font-semibold">Kasir baru</h2>
          <form onSubmit={submit} className="mt-3 space-y-2">
            <label htmlFor="nama-staf" className="block text-sm">
              Nama
            </label>
            <input
              id="nama-staf"
              type="text"
              value={nama}
              onChange={(e) => setNama(e.target.value)}
              placeholder="Rina"
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
            />

            <label htmlFor="email-staf" className="block text-sm">
              Email
            </label>
            <input
              id="email-staf"
              type="email"
              autoComplete="off"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="rina@kafe.test"
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
            />

            <label htmlFor="sandi-staf" className="block text-sm">
              Sandi awal
            </label>
            <input
              id="sandi-staf"
              type="password"
              autoComplete="new-password"
              value={sandi}
              onChange={(e) => setSandi(e.target.value)}
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
            />
            {/* Aturannya disebut SEBELUM tombol ditekan, bukan dibiarkan muncul
                sebagai 422 dari server: owner yang tak tahu syaratnya akan
                mencoba berulang kali dengan sandi yang sama-sama ditolak. */}
            <p className="text-xs text-stone-600">
              Minimal 8 karakter, mengandung huruf besar, huruf kecil, dan angka. Kasir memakainya
              untuk masuk pertama kali — beri tahu langsung ke orangnya.
            </p>
            <button
              type="submit"
              disabled={sibuk}
              className="w-full rounded-md bg-stone-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              Tambah kasir
            </button>
          </form>
        </section>

        <section className="mt-4">
          <h2 className="text-sm font-semibold">Daftar karyawan</h2>
          <ul className="mt-2 space-y-2">
            {daftar?.map((s) => (
              <li key={s.id} className="rounded-md border border-stone-200 bg-white p-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">
                      {s.nama}
                      {!s.aktif && <span className="ml-2 text-xs text-stone-500">· Nonaktif</span>}
                    </p>
                    <p className="truncate text-xs text-stone-500">
                      {s.email} · {s.peran === 'owner' ? 'Pemilik' : 'Kasir'}
                    </p>
                  </div>

                  {/* Baris pemilik tak punya tombol sama sekali. Owner hari ini
                    satu-satunya per tenant, jadi menonaktifkan diri sendiri
                    mematikan tenant permanen — tak ada akun berwenang tersisa
                    untuk menghidupkannya. IAM sudah menolaknya dengan 422;
                    yang di sini semata agar tombolnya tak pernah menggoda. */}
                  {/* Baris pemilik juga tak punya tombol reset sandi — bukan
                    cuma nonaktifkan. Server menolaknya (422) supaya syarat
                    "sebutkan sandi lama" pada ganti-sandi tak bisa dilangkahi
                    lewat pintu samping; owner mengganti sandinya di /sandi. */}
                  {s.peran !== 'owner' && (
                    <div className="flex shrink-0 items-center gap-2">
                      <button
                        type="button"
                        onClick={() => {
                          setResetUntuk(resetUntuk === s.id ? null : s.id)
                          setSandiBaru('')
                        }}
                        disabled={sibuk}
                        aria-label={`Reset sandi ${s.nama}`}
                        className="rounded-md border border-stone-300 px-3 py-1.5 text-xs disabled:opacity-50"
                      >
                        Reset sandi
                      </button>
                      <button
                        type="button"
                        onClick={() => jalankan(ubahAktifStaf(s.id, !s.aktif))}
                        disabled={sibuk}
                        aria-label={`${s.aktif ? 'Nonaktifkan' : 'Aktifkan'} ${s.nama}`}
                        className="rounded-md border border-stone-300 px-3 py-1.5 text-xs disabled:opacity-50"
                      >
                        {s.aktif ? 'Nonaktifkan' : 'Aktifkan'}
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          if (confirm(`Hapus "${s.nama}" dari daftar karyawan?`)) jalankan(hapusStaf(s.id))
                        }}
                        disabled={sibuk}
                        aria-label={`Hapus ${s.nama}`}
                        className="rounded-md border border-brick px-3 py-1.5 text-xs text-brick disabled:opacity-50"
                      >
                        Hapus
                      </button>
                    </div>
                  )}
                </div>

                {resetUntuk === s.id && (
                  <form
                    className="mt-3 border-t border-stone-200 pt-3"
                    onSubmit={(e) => {
                      e.preventDefault()
                      if (sandiBaru === '') {
                        setGalat('Sandi baru tak boleh kosong.')
                        return
                      }
                      jalankan(
                        resetSandiStaf(s.id, sandiBaru).then(() => {
                          setResetUntuk(null)
                          setSandiBaru('')
                        }),
                      )
                    }}
                  >
                    <label htmlFor={`sandi-baru-${s.id}`} className="block text-xs font-semibold">
                      Sandi baru untuk {s.nama}
                    </label>
                    <div className="mt-1.5 flex gap-2">
                      <input
                        id={`sandi-baru-${s.id}`}
                        type="password"
                        autoComplete="new-password"
                        value={sandiBaru}
                        onChange={(e) => setSandiBaru(e.target.value)}
                        className="min-w-0 flex-1 rounded-md border border-stone-300 px-3 py-2 text-sm"
                      />
                      <button
                        type="submit"
                        disabled={sibuk}
                        className="shrink-0 rounded-md bg-stone-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"
                      >
                        Simpan
                      </button>
                    </div>
                    {/* Owner akan melihat sandi ini; tak ada cara lain — dialah
                        yang mengetiknya. Yang bisa dilakukan layar cuma
                        mengingatkan bahwa pemakainya berhak menggantinya. */}
                    <p className="mt-1.5 text-xs text-stone-600">
                      Minimal 8 karakter dengan huruf besar, kecil, dan angka. Beri tahu langsung ke
                      orangnya — dia bisa menggantinya sendiri lewat Ganti sandi.
                    </p>
                  </form>
                )}
              </li>
            ))}
          </ul>

          {/* Disebut apa adanya: sejak polling cekAkunAktif, akun nonaktif/dihapus
              dikeluarkan otomatis oleh aplikasi kasir dalam hitungan detik. */}
          <p className="mt-3 text-xs text-stone-600">
            Menonaktifkan atau menghapus akun menutup login berikutnya dan langsung
            mengeluarkan kasir yang sedang membuka aplikasi — paling lama 30 detik.
          </p>
        </section>
      </main>
    </div>
  )
}
