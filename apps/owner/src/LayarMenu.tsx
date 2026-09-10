import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import {
  ambilBahan,
  ambilKategori,
  ambilProduk,
  ambilResep,
  buatKategori,
  buatProduk,
  buatResep,
  hapusKategori,
  hapusProduk,
  hapusResep,
  SESI_HABIS,
  ubahHargaBeli,
  ubahProduk,
  ubahTakaran,
  unggahFotoProduk,
  urlGambar,
  type Bahan,
  type BarisResep,
  type Kategori,
  type Produk,
} from './api'
import { rupiah } from './format'
import PanelResep from './PanelResep'

/**
 * Kotak foto produk, 64×64. Klik = buka dialog berkas lewat onPilih.
 *
 * Tanpa foto ia menampilkan ikon gambar samar — pemilik kafe langsung melihat
 * produk mana yang belum punya foto, dan satu ketukan memulai unggahannya.
 */
function GambarProduk({ src, onPilih }: { src: string | null; onPilih: () => void }) {
  return (
    <button
      type="button"
      onClick={onPilih}
      aria-label={src ? 'Ganti foto produk' : 'Tambah foto produk'}
      className="h-16 w-16 shrink-0 overflow-hidden rounded-md border border-stone-200 bg-stone-100"
    >
      {src ? (
        <img src={src} alt="" className="h-full w-full object-cover" />
      ) : (
        <svg
          className="m-auto h-6 w-6 text-stone-400"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          aria-hidden="true"
        >
          <rect x="3" y="4" width="18" height="16" rx="2" />
          <circle cx="8.5" cy="9.5" r="1.5" />
          <path d="m3 16 5-4 4 3 3-2 6 5" />
        </svg>
      )}
    </button>
  )
}

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
  const [bahan, setBahan] = useState<Bahan[]>([])
  const [resep, setResep] = useState<BarisResep[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  const [versi, setVersi] = useState(0)
  /** Produk yang panel resepnya sedang terbuka — satu saja, `null` kalau tak ada. */
  const [resepDibuka, setResepDibuka] = useState<string | null>(null)
  /** Produk yang dialog berkasnya baru dibuka — target unggahan foto. */
  const [fotoUntuk, setFotoUntuk] = useState<string | null>(null)
  const inputFotoRef = useRef<HTMLInputElement>(null)

  const [namaKategori, setNamaKategori] = useState('')
  const [namaProduk, setNamaProduk] = useState('')
  const [harga, setHarga] = useState('')
  const [kategoriPilihan, setKategoriPilihan] = useState('')
  const [cari, setCari] = useState('')

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    setSibuk(true)

    // Paralel: empat daftar yang tak saling bergantung, dan owner menunggu
    // semuanya sebelum layar berarti apa-apa.
    //
    // Resep diambil SEKALIGUS untuk semua produk, bukan per produk saat panelnya
    // dibuka: yang paling berguna di layar ini justru penandaan produk mana yang
    // belum punya resep, dan itu mustahil dijawab tanpa melihat semuanya.
    Promise.all([ambilKategori(), ambilProduk(), ambilBahan(), ambilResep()])
      .then(([k, p, b, r]) => {
        if (batal) return
        setKategori(k)
        setProduk(p)
        setBahan(b)
        setResep(r)
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

  // Saring produk untuk kotak cari — tanpa useMemo: daftar kafe cuma puluhan
  // baris, dan menghitung ulang tiap render lebih murah daripada menyimpan
  // cache yang bisa basi saat `versi` berubah.
  const kCari = cari.trim().toLowerCase()
  const produkTersaring =
    produk === null || kCari === '' ? produk : produk.filter((p) => p.nama.toLowerCase().includes(kCari))

  // Tombol kategori siap-pakai: cuma menawarkan yang BELUM ada, supaya owner
  // tak menciptakan duplikat dengan satu ketukan.
  const NAMA_KATEGORI_UMUM = ['Makanan', 'Minuman', 'Snack', 'Dessert']
  const namaKategoriAda = new Set((kategori ?? []).map((k) => k.nama.toLowerCase()))
  const saranKategori = NAMA_KATEGORI_UMUM.filter((n) => !namaKategoriAda.has(n.toLowerCase()))

  const resepUntuk = (produkId: string): BarisResep[] =>
    resep?.filter((r) => r.produkId === produkId) ?? []

  // Harga beli tiap bahan, dipetakan dari id — dipakai menghitung HPP per produk
  // dari resepnya. Tak butuh endpoint baru: resep & harga beli sudah termuat di
  // layar ini.
  const hargaBeliDari = new Map(bahan.map((b) => [b.id, b.hargaBeli]))

  /** HPP produk = Σ(takaran resep × harga beli bahan). null = resep belum termuat. */
  const hppUntuk = (produkId: string): number | null => {
    if (resep === null) return null
    return resepUntuk(produkId).reduce(
      (total, r) => total + (hargaBeliDari.get(r.bahanId) ?? 0) * r.takaran,
      0,
    )
  }

  return (
    <div className="min-h-svh bg-stone-50 text-stone-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Menu</h1>
          <p className="text-sm text-stone-600">
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
          <h2 className="text-sm font-semibold">Kategori</h2>
          {belumAdaKategori && (
            <p className="mt-2 rounded-md border border-sage-300 bg-sage-50 p-2 text-xs text-sage-900">
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
                <div className="flex items-center gap-2">
                  {!k.aktif && (
                    <span className="text-xs font-semibold text-sage-800">
                      Nonaktif · isinya tak tampil
                    </span>
                  )}
                  <button
                    type="button"
                    onClick={() => {
                      if (confirm(`Hapus kategori "${k.nama}"?`)) jalankan(hapusKategori(k.id))
                    }}
                    disabled={sibuk}
                    className="text-xs text-brick disabled:opacity-50"
                  >
                    Hapus
                  </button>
                </div>
              </li>
            ))}
          </ul>
          {saranKategori.length > 0 && (
            <div className="mt-3 flex flex-wrap gap-2">
              {saranKategori.map((n) => (
                <button
                  key={n}
                  type="button"
                  onClick={() => jalankan(buatKategori(n))}
                  disabled={sibuk}
                  className="rounded-full border border-stone-300 px-3 py-1 text-xs disabled:opacity-50"
                >
                  + {n}
                </button>
              ))}
            </div>
          )}
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
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
            />
            <button
              type="submit"
              disabled={sibuk}
              className="shrink-0 rounded-md border border-stone-300 px-3 py-2 text-sm disabled:opacity-50"
            >
              Tambah kategori
            </button>
          </form>
        </section>

        <section className="mt-4 rounded-md border border-stone-200 bg-white p-4">
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
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
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
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm tabular-nums"
            />
            <label htmlFor="kategori-produk" className="block text-sm">
              Kategori
            </label>
            <select
              id="kategori-produk"
              value={kategoriPilihan}
              onChange={(e) => setKategoriPilihan(e.target.value)}
              className="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
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
              className="w-full rounded-md bg-stone-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              Tambah produk
            </button>
          </form>
        </section>

        <section className="mt-4">
          <div className="flex items-center justify-between gap-2">
            <h2 className="text-sm font-semibold">Daftar produk</h2>
            <input
              type="search"
              value={cari}
              onChange={(e) => setCari(e.target.value)}
              placeholder="Cari produk…"
              aria-label="Cari produk"
              className="w-40 rounded-md border border-stone-300 px-2.5 py-1.5 text-sm"
            />
          </div>
          {produk !== null && produk.length === 0 && (
            <p className="py-10 text-center text-sm text-stone-600">
              Belum ada produk. Menu pelanggan masih kosong.
            </p>
          )}
          <ul className="mt-2 space-y-2">
            {produkTersaring?.map((p) => {
              const namaKat = namaKategoriDari(p.kategoriId)
              const barisResep = resepUntuk(p.id)
              // Selama resep belum termuat, JANGAN menuduh apa pun: `resep`
              // masih null berarti belum tahu, bukan "tak punya".
              const tanpaResep = resep !== null && barisResep.length === 0
              const hpp = hppUntuk(p.id)

              return (
                <li key={p.id} className="rounded-md border border-stone-200 bg-white p-3">
                  <div className="flex items-start gap-3">
                    <GambarProduk
                      src={urlGambar(p.gambarUrl)}
                      onPilih={() => {
                        setFotoUntuk(p.id)
                        inputFotoRef.current?.click()
                      }}
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-baseline justify-between gap-3">
                        <p className="text-sm font-semibold">{p.nama}</p>
                        <p className="shrink-0 text-sm tabular-nums">{rupiah(p.harga)}</p>
                      </div>
                      <p className="mt-1 text-xs text-stone-500">{namaKat ?? 'Tanpa kategori'}</p>
                      {/* HPP dihitung langsung dari resep × harga beli — owner
                          melihat hasilnya di sini, bukan menebak dari layar Bahan.
                          Hanya muncul kalau produknya PUNYA resep (tanpa resep
                          sudah diurus peringatan tersendiri di bawah). */}
                      {barisResep.length > 0 && hpp !== null && (
                        <p className={`mt-0.5 text-xs ${hpp > 0 ? 'text-stone-600' : 'font-semibold text-sage-800'}`}>
                          {hpp > 0 ? `HPP ${rupiah(hpp)}` : 'HPP belum kehitung — isi harga beli bahan'}
                        </p>
                      )}
                    </div>
                  </div>

                  {/* Produk lama yang terlanjur tanpa kategori. Diberi tahu di
                      barisnya sendiri, bukan sebagai peringatan umum: yang perlu
                      diperbaiki adalah produk ini, dan owner harus tahu yang
                      mana. */}
                  {p.kategoriId === null && (
                    <div className="mt-1 rounded-md border border-sage-300 bg-sage-50 p-2 text-xs text-sage-900">
                      Tidak muncul di menu pelanggan. Pilih kategorinya:
                      <select
                        aria-label={`Kategori untuk ${p.nama}`}
                        value=""
                        onChange={(e) =>
                          e.target.value !== '' &&
                          jalankan(ubahProduk(p.id, { kategoriId: e.target.value }))
                        }
                        className="mt-1 block w-full rounded-md border border-sage-300 bg-white px-2 py-1"
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
                    className="mt-2 rounded-md border border-stone-300 px-3 py-1.5 text-xs disabled:opacity-50"
                  >
                    {p.tersedia ? 'Habiskan (sembunyikan)' : 'Sediakan lagi'}
                  </button>
                  <button
                    type="button"
                    onClick={() => setResepDibuka((kini) => (kini === p.id ? null : p.id))}
                    aria-label={`Resep ${p.nama}`}
                    aria-expanded={resepDibuka === p.id}
                    className="ml-2 rounded-md border border-stone-300 px-3 py-1.5 text-xs"
                  >
                    Resep{barisResep.length > 0 && ` (${barisResep.length})`}
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      if (confirm(`Hapus "${p.nama}"? Ini permanen.`)) jalankan(hapusProduk(p.id))
                    }}
                    disabled={sibuk}
                    className="ml-2 rounded-md border border-brick px-3 py-1.5 text-xs text-brick disabled:opacity-50"
                  >
                    Hapus
                  </button>
                  {!p.tersedia && (
                    <span className="ml-2 text-xs font-semibold text-stone-600">Sedang habis</span>
                  )}

                  {/* Sepola penanda "tanpa kategori" di atas: kerusakannya sunyi,
                      jadi yang menyebutkannya harus barisnya sendiri. Produk tanpa
                      resep lolos gerbang stok tanpa pemeriksaan apa pun — pelanggan
                      tetap bisa membayar kopi yang bijinya sudah habis. */}
                  {tanpaResep && (
                    <p className="mt-1 text-xs font-semibold text-sage-800">
                      Tanpa resep · stoknya tak pernah diperiksa
                    </p>
                  )}

                  {resepDibuka === p.id && (
                    <PanelResep
                      namaProduk={p.nama}
                      baris={barisResep}
                      bahan={bahan}
                      sibuk={sibuk}
                      onTambah={(bahanId, takaran) => jalankan(buatResep(p.id, bahanId, takaran))}
                      onUbah={(id, takaran) => jalankan(ubahTakaran(id, takaran))}
                      onHapus={(id) => jalankan(hapusResep(id))}
                      onUbahHargaBeli={(bahanId, harga) => jalankan(ubahHargaBeli(bahanId, harga))}
                    />
                  )}
                </li>
              )
            })}
          </ul>
        </section>
      </main>

      {/* Input berkas tersembunyi, satu untuk seluruh daftar. `fotoUntuk` mencatat
          produk mana yang sedang dibidik; menekan kotak foto memicunya lewat
          inputFotoRef. value direset habis dipilih supaya berkas yang sama bisa
          dipilih lagi untuk produk lain. */}
      <input
        ref={inputFotoRef}
        type="file"
        accept="image/png,image/jpeg"
        className="hidden"
        onChange={(e) => {
          const berkas = e.target.files?.[0]
          e.target.value = ''
          if (berkas && fotoUntuk) jalankan(unggahFotoProduk(fotoUntuk, berkas))
        }}
      />
    </div>
  )
}
