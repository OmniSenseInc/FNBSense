import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import {
  ambilKategori,
  ambilProduk,
  buatKategori,
  buatProduk,
  SESI_HABIS,
  ubahProduk,
  type Kategori,
  type Produk,
} from './api'
import { rupiah } from './format'

/**
 * Menu: kategori & produk. Owner saja (`role:owner` di Catalog).
 *
 * Inilah layar yang membuat QR pelanggan berguna — tanpa produk, memindai meja
 * cuma membuka daftar kosong.
 *
 * Kategori DIWAJIBKAN saat membuat produk, walau server masih menerima null.
 * Alasannya bukan kerapian: `MenuController` hanya menelusuri produk lewat
 * kategori aktif, jadi produk tanpa kategori tak pernah sampai ke pelanggan dan
 * tak ada satu pun pesan yang memberi tahu. Layar ini menolak membuat kerusakan
 * itu, dan menandai produk lama yang sudah terlanjur mengalaminya.
 */
export default function LayarMenu({ onKeluar }: { onKeluar: () => void }) {
  const [kategori, setKategori] = useState<Kategori[] | null>(null)
  const [produk, setProduk] = useState<Produk[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  const [versi, setVersi] = useState(0)

  const [namaKategori, setNamaKategori] = useState('')
  const [namaProduk, setNamaProduk] = useState('')
  const [harga, setHarga] = useState('')
  const [kategoriPilihan, setKategoriPilihan] = useState('')

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    setSibuk(true)

    // Paralel: dua daftar yang tak saling bergantung, dan owner menunggu
    // keduanya sebelum layar berarti apa-apa.
    Promise.all([ambilKategori(), ambilProduk()])
      .then(([k, p]) => {
        if (batal) return
        setKategori(k)
        setProduk(p)
        setGalat(null)
      })
      .catch((err: unknown) => {
        if (batal) return
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal memuat menu.')
      })
      .finally(() => {
        if (!batal) setSibuk(false)
      })

    return () => {
      batal = true
    }
  }, [versi])

  /** Rupiah dari isian: digit saja — "200.000" dibaca JavaScript sebagai 200. */
  const keRupiah = (teks: string): number | null => {
    const bersih = teks.trim()
    if (!/^\d+$/.test(bersih)) return null

    return Number.isSafeInteger(Number(bersih)) ? Number(bersih) : null
  }

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

  const submitKategori = (e: React.FormEvent) => {
    e.preventDefault()
    const nama = namaKategori.trim()
    if (nama === '') {
      setGalat('Nama kategori tak boleh kosong.')
      return
    }
    setNamaKategori('')
    jalankan(buatKategori(nama))
  }

  const submitProduk = (e: React.FormEvent) => {
    e.preventDefault()
    const nama = namaProduk.trim()
    const nilai = keRupiah(harga)

    if (nama === '') {
      setGalat('Nama produk tak boleh kosong.')
      return
    }
    if (nilai === null) {
      setGalat('Harga harus angka saja, tanpa titik atau koma. Contoh: 25000')
      return
    }
    if (kategoriPilihan === '') {
      setGalat('Pilih kategorinya dulu. Produk tanpa kategori tak akan muncul di menu pelanggan.')
      return
    }

    setNamaProduk('')
    setHarga('')
    jalankan(buatProduk(nama, nilai, kategoriPilihan))
  }

  const namaKategoriDari = (id: string | null): string | null =>
    id === null ? null : (kategori?.find((k) => k.id === id)?.nama ?? null)

  const belumAdaKategori = kategori !== null && kategori.length === 0

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Menu</h1>
          <p className="text-sm text-slate-600">
            {produk === null
              ? 'Memuat…'
              : `${produk.length} produk · ${kategori?.length ?? 0} kategori`}
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
          <h2 className="text-sm font-semibold">Kategori</h2>
          {belumAdaKategori && (
            <p className="mt-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
              Belum ada kategori. Buat satu dulu — produk baru wajib punya kategori supaya tampil di
              menu pelanggan.
            </p>
          )}
          <ul className="mt-2 space-y-1 text-sm">
            {kategori?.map((k) => (
              <li key={k.id} className="flex items-center justify-between gap-3">
                <span>{k.nama}</span>
                {/* Kategori nonaktif menyembunyikan seluruh isinya dari
                    pelanggan. Kalau tak disebut di sini, owner mencari
                    penyebabnya di produk — tempat yang salah. */}
                {!k.aktif && (
                  <span className="text-xs font-semibold text-amber-800">
                    Nonaktif · isinya tak tampil
                  </span>
                )}
              </li>
            ))}
          </ul>
          <form onSubmit={submitKategori} className="mt-3 flex gap-2">
            <label htmlFor="nama-kategori" className="sr-only">
              Nama kategori
            </label>
            <input
              id="nama-kategori"
              type="text"
              value={namaKategori}
              onChange={(e) => setNamaKategori(e.target.value)}
              placeholder="Kopi"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            <button
              type="submit"
              disabled={sibuk}
              className="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-sm disabled:opacity-50"
            >
              Tambah kategori
            </button>
          </form>
        </section>

        <section className="mt-4 rounded-md border border-slate-200 bg-white p-4">
          <h2 className="text-sm font-semibold">Produk baru</h2>
          <form onSubmit={submitProduk} className="mt-3 space-y-2">
            <label htmlFor="nama-produk" className="block text-sm">
              Nama
            </label>
            <input
              id="nama-produk"
              type="text"
              value={namaProduk}
              onChange={(e) => setNamaProduk(e.target.value)}
              placeholder="Kopi Susu"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
            <label htmlFor="harga-produk" className="block text-sm">
              Harga
            </label>
            <input
              id="harga-produk"
              type="text"
              inputMode="numeric"
              value={harga}
              onChange={(e) => setHarga(e.target.value)}
              placeholder="25000"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm tabular-nums"
            />
            <label htmlFor="kategori-produk" className="block text-sm">
              Kategori
            </label>
            <select
              id="kategori-produk"
              value={kategoriPilihan}
              onChange={(e) => setKategoriPilihan(e.target.value)}
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">— pilih —</option>
              {kategori?.map((k) => (
                <option key={k.id} value={k.id}>
                  {k.nama}
                </option>
              ))}
            </select>
            <button
              type="submit"
              disabled={sibuk || belumAdaKategori}
              className="w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              Tambah produk
            </button>
          </form>
        </section>

        <section className="mt-4">
          <h2 className="text-sm font-semibold">Daftar produk</h2>
          {produk !== null && produk.length === 0 && (
            <p className="py-10 text-center text-sm text-slate-600">
              Belum ada produk. Menu pelanggan masih kosong.
            </p>
          )}
          <ul className="mt-2 space-y-2">
            {produk?.map((p) => {
              const namaKat = namaKategoriDari(p.kategoriId)

              return (
                <li key={p.id} className="rounded-md border border-slate-200 bg-white p-3">
                  <div className="flex items-baseline justify-between gap-3">
                    <p className="text-sm font-semibold">{p.nama}</p>
                    <p className="shrink-0 text-sm tabular-nums">{rupiah(p.harga)}</p>
                  </div>
                  <p className="mt-1 text-xs text-slate-500">{namaKat ?? 'Tanpa kategori'}</p>

                  {/* Produk lama yang terlanjur tanpa kategori. Diberi tahu di
                      barisnya sendiri, bukan sebagai peringatan umum: yang perlu
                      diperbaiki adalah produk ini, dan owner harus tahu yang
                      mana. */}
                  {p.kategoriId === null && (
                    <div className="mt-1 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
                      Tidak muncul di menu pelanggan. Pilih kategorinya:
                      <select
                        aria-label={`Kategori untuk ${p.nama}`}
                        value=""
                        onChange={(e) =>
                          e.target.value !== '' &&
                          jalankan(ubahProduk(p.id, { kategoriId: e.target.value }))
                        }
                        className="mt-1 block w-full rounded-md border border-amber-300 bg-white px-2 py-1"
                      >
                        <option value="">— pilih —</option>
                        {kategori?.map((k) => (
                          <option key={k.id} value={k.id}>
                            {k.nama}
                          </option>
                        ))}
                      </select>
                    </div>
                  )}

                  <button
                    type="button"
                    onClick={() => jalankan(ubahProduk(p.id, { tersedia: !p.tersedia }))}
                    disabled={sibuk}
                    className="mt-2 rounded-md border border-slate-300 px-3 py-1.5 text-xs disabled:opacity-50"
                  >
                    {p.tersedia ? 'Habiskan (sembunyikan)' : 'Sediakan lagi'}
                  </button>
                  {!p.tersedia && (
                    <span className="ml-2 text-xs font-semibold text-slate-600">Sedang habis</span>
                  )}
                </li>
              )
            })}
          </ul>
        </section>
      </main>
    </div>
  )
}
