import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ambilBahan, buatBahan, hapusBahan, SATUAN, SESI_HABIS, type Bahan } from './api'

/**
 * Bahan mentah: apa yang disimpan di gudang, dalam satuan apa.
 *
 * Layar paling dasar dari rantai stok, dan yang terakhir dibuat — semua yang
 * lain sudah menganggapnya ada. Saldo di layar stok dan takaran di panel resep
 * dua-duanya menunjuk ke baris yang lahir di sini; sebelum ada layar ini,
 * satu-satunya jalan mendaftarkan bahan adalah curl.
 *
 * Owner saja. Tak ada penjaga di sini, sepola /menu dan /setelan: penjaganya
 * `role:owner` di Catalog, dan kasir yang memaksa alamat ini melihat pesan
 * galat, bukan daftar yang bisa diubahnya.
 *
 * TIDAK dipolling, sepola layar stok: daftar bahan berubah beberapa kali sebulan,
 * bukan beberapa kali semenit.
 */
export default function LayarBahan({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Bahan[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  const [versi, setVersi] = useState(0)

  const [nama, setNama] = useState('')
  const [satuan, setSatuan] = useState<string>(SATUAN[0])
  /** Bahan yang tombol hapusnya sudah diketuk sekali — ketukan kedua yang menghapus. */
  const [konfirmasi, setKonfirmasi] = useState<string | null>(null)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    setSibuk(true)

    ambilBahan()
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
        setGalat(err instanceof Error ? err.message : 'Gagal memuat bahan.')
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
    setKonfirmasi(null)
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
    const bersih = nama.trim()
    if (bersih === '') {
      setGalat('Nama bahan tak boleh kosong.')
      return
    }

    setNama('')
    jalankan(buatBahan(bersih, satuan))
  }

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Bahan</h1>
          <p className="text-sm text-slate-600">
            {daftar === null ? 'Memuat…' : `${daftar.length} bahan`}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setVersi((v) => v + 1)}
            disabled={sibuk}
            className="rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
          >
            {sibuk ? 'Memuat…' : 'Muat ulang'}
          </button>
          <Link to="/menu" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
            Menu
          </Link>
          <Link to="/" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
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

        <section className="rounded-md border border-slate-200 bg-white p-4">
          <h2 className="text-sm font-semibold">Bahan baru</h2>
          <form onSubmit={submit} className="mt-3 space-y-2">
            <label htmlFor="nama-bahan" className="block text-sm">
              Nama
            </label>
            <input
              id="nama-bahan"
              type="text"
              value={nama}
              onChange={(e) => setNama(e.target.value)}
              placeholder="Susu Full Cream"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            <label htmlFor="satuan-bahan" className="block text-sm">
              Satuan
            </label>
            <select
              id="satuan-bahan"
              value={satuan}
              onChange={(e) => setSatuan(e.target.value)}
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            >
              {SATUAN.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
            {/* Satuan tak bisa diubah setelah bahan dipakai: mengganti g jadi ml
                membuat setiap takaran resep dan setiap saldo stok yang sudah
                tercatat berarti hal lain, tanpa satu angka pun berubah. Karena
                itu disebut di sini, saat pilihannya masih murah. */}
            <p className="text-xs text-slate-600">
              Pilih satuan yang benar sekarang — resep dan saldo stok memakainya apa adanya.
            </p>
            <button
              type="submit"
              disabled={sibuk}
              className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              Tambah bahan
            </button>
          </form>
        </section>

        <section className="mt-4">
          <h2 className="text-sm font-semibold">Daftar bahan</h2>
          {daftar !== null && daftar.length === 0 && (
            <p className="py-10 text-center text-sm text-slate-600">
              Belum ada bahan. Tanpa bahan, resep tak bisa dibuat dan stok tak pernah diperiksa
              sebelum pelanggan membayar.
            </p>
          )}
          <ul className="mt-2 space-y-2">
            {daftar?.map((b) => (
              <li
                key={b.id}
                className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white p-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold">{b.nama}</p>
                  <p className="text-xs text-slate-500">Satuan: {b.satuan}</p>
                </div>

                {/* Dua ketukan, bukan satu: menghapus bahan ikut menghapus setiap
                    baris resep yang memakainya (FK cascade di Catalog), dan
                    saldo stoknya di Inventory ditinggal tanpa nama karena kedua
                    basis data itu terpisah. Terlalu mahal untuk sekali salah
                    sentuh di layar sempit. */}
                {konfirmasi === b.id ? (
                  <div className="flex shrink-0 items-center gap-2">
                    <span className="text-xs text-amber-900">Resepnya ikut terhapus.</span>
                    <button
                      type="button"
                      onClick={() => jalankan(hapusBahan(b.id))}
                      disabled={sibuk}
                      className="rounded-md border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-900 disabled:opacity-50"
                    >
                      Hapus {b.nama}
                    </button>
                    <button
                      type="button"
                      onClick={() => setKonfirmasi(null)}
                      className="rounded-md border border-slate-300 px-3 py-1.5 text-xs"
                    >
                      Batal
                    </button>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() => setKonfirmasi(b.id)}
                    aria-label={`Hapus bahan ${b.nama}`}
                    className="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-xs"
                  >
                    Hapus
                  </button>
                )}
              </li>
            ))}
          </ul>
        </section>
      </main>
    </div>
  )
}
