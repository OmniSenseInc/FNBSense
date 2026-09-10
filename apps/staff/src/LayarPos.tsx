import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Minus, Plus, Trash2 } from 'lucide-react'
import {
  ambilMejaKasir,
  ambilMenuKasir,
  buatPesananKasir,
  SESI_HABIS,
  type KategoriMenu,
  type MejaKasir,
} from './api'
import { rupiah } from './format'
import {
  hapusKeranjang,
  jumlahItem,
  kurangKeranjang,
  naikkanQty,
  tambahKeranjang,
  totalKeranjang,
  type BarisKeranjang,
} from './pos'

/**
 * POS kasir — buat pesanan walk-in / telepon / meja.
 *
 * Kasir mengetuk produk untuk menambah ke keranjang, memilih meja (dine-in)
 * atau bawa pulang, lalu membuat order PENDING. Order itu masuk antrean dan
 * dibayar lewat layar nota seperti order QR biasa — uang tetap urusan server.
 *
 * Harga di layar ini cuma PRAKIRAAN (jumlah harga menu). Total akhir (pajak,
 * servis, promo) dihitung server saat order dibuat, dan itulah yang tampil di
 * nota — jadi layar ini tak pernah menampilkan angka uang "final" bohong.
 */
export default function LayarPos({ onKeluar }: { onKeluar: () => void }) {
  const navigate = useNavigate()
  const [menu, setMenu] = useState<KategoriMenu[] | null>(null)
  const [meja, setMeja] = useState<MejaKasir[]>([])
  const [kategoriAktif, setKategoriAktif] = useState<string | null>(null)
  const [keranjang, setKeranjang] = useState<BarisKeranjang[]>([])
  const [tipe, setTipe] = useState<'dine_in' | 'takeaway'>('dine_in')
  const [mejaDipilih, setMejaDipilih] = useState<string | null>(null)
  const [nama, setNama] = useState('')
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  // Guard SINKRON untuk double-tap: setState reaktif baru menutup tombol pada
  // render berikutnya, sedangkan dua klik dalam tick yang sama tetap jalan.
  const sibukRef = useRef(false)

  useEffect(() => {
    let batal = false

    ambilMenuKasir()
      .then((m) => {
        if (batal) return
        setMenu(m)
        setKategoriAktif(m[0]?.id ?? null)
      })
      .catch((e: unknown) => {
        if (batal) return
        if (e instanceof Error && e.message === SESI_HABIS) onKeluar()
        else setGalat(e instanceof Error ? e.message : 'Gagal memuat menu.')
      })

    ambilMejaKasir()
      .then((m) => {
        if (!batal) setMeja(m)
      })
      .catch(() => {})

    return () => {
      batal = true
    }
  }, [onKeluar])

  const produkTampil = useMemo(() => {
    return menu?.find((k) => k.id === kategoriAktif)?.produk ?? []
  }, [menu, kategoriAktif])

  const total = totalKeranjang(keranjang)

  const submit = async () => {
    if (sibukRef.current) return
    if (keranjang.length === 0) {
      setGalat('Pilih menu dulu sebelum membuat pesanan.')
      return
    }
    if (tipe === 'dine_in' && !mejaDipilih) {
      setGalat('Pilih meja untuk pesanan dine-in.')
      return
    }

    sibukRef.current = true
    setSibuk(true)
    setGalat(null)
    try {
      const pesanan = await buatPesananKasir({
        orderType: tipe,
        tableId: tipe === 'dine_in' ? mejaDipilih : null,
        customerName: nama.trim(),
        items: keranjang.map((b) => ({ product_id: b.produkId, qty: b.qty })),
      })
      setKeranjang([])
      setNama('')
      setMejaDipilih(null)
      // Lanjut ke nota order itu — di sana kasir mengonfirmasi pembayaran.
      navigate(`/nota/${pesanan.id}`)
    } catch (e: unknown) {
      if (e instanceof Error && e.message === SESI_HABIS) onKeluar()
      else setGalat(e instanceof Error ? e.message : 'Gagal membuat pesanan.')
    } finally {
      sibukRef.current = false
      setSibuk(false)
    }
  }

  return (
    <div className="min-h-svh bg-stone-50 text-stone-900 flex flex-col">
      <header className="sticky top-0 z-20 border-b border-stone-200 bg-white px-4 py-3 space-y-3">
        <div className="flex items-center justify-between">
          <h1 className="text-base font-semibold">Kasir</h1>
          <Link to="/" className="rounded-md border border-stone-300 px-3 py-1.5 text-sm">
            ← Antrean
          </Link>
        </div>

        <div className="flex rounded-xl bg-stone-100 p-1 gap-1">
          <button
            type="button"
            onClick={() => setTipe('dine_in')}
            className={
              'flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition ' +
              (tipe === 'dine_in' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500')
            }
          >
            Dine-in
          </button>
          <button
            type="button"
            onClick={() => setTipe('takeaway')}
            className={
              'flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition ' +
              (tipe === 'takeaway' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500')
            }
          >
            Bawa pulang
          </button>
        </div>

        {tipe === 'dine_in' && (
          <div className="flex gap-2 overflow-x-auto pb-1">
            {meja.map((m) => (
              <button
                key={m.id}
                type="button"
                onClick={() => setMejaDipilih(m.id)}
                className={
                  'shrink-0 rounded-lg border px-3 py-1.5 text-sm ' +
                  (mejaDipilih === m.id
                    ? 'border-sage-500 bg-sage-50 text-sage-800 font-semibold'
                    : 'border-stone-300 bg-white text-stone-600')
                }
              >
                {m.label}
              </button>
            ))}
            {meja.length === 0 && (
              <p className="text-xs text-stone-400 py-1">Belum ada meja aktif.</p>
            )}
          </div>
        )}

        <input
          type="text"
          value={nama}
          onChange={(e) => setNama(e.target.value)}
          placeholder="Nama pelanggan (opsional)"
          className="w-full rounded-md border border-stone-300 px-3 py-1.5 text-sm"
        />
      </header>

      <main className="flex-1 overflow-y-auto px-4 py-3">
        {galat && (
          <p role="alert" className="mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm">
            {galat}
          </p>
        )}

        {menu === null ? (
          <p className="py-16 text-center text-sm text-stone-400">Memuat menu…</p>
        ) : menu.length === 0 ? (
          <p className="py-16 text-center text-sm text-stone-400">
            Menu masih kosong. Tambahkan produk dulu di dashboard owner.
          </p>
        ) : (
          <>
            <div className="mb-3 flex gap-2 overflow-x-auto">
              {menu.map((k) => (
                <button
                  key={k.id}
                  type="button"
                  onClick={() => setKategoriAktif(k.id)}
                  className={
                    'shrink-0 rounded-full px-3 py-1.5 text-sm ' +
                    (kategoriAktif === k.id
                      ? 'bg-stone-900 text-white font-semibold'
                      : 'bg-stone-200 text-stone-600')
                  }
                >
                  {k.nama}
                </button>
              ))}
            </div>

            <div className="grid grid-cols-2 gap-2">
              {produkTampil.map((p) => (
                <button
                  key={p.id}
                  type="button"
                  disabled={p.habis}
                  onClick={() => setKeranjang(tambahKeranjang(keranjang, p))}
                  className={
                    'rounded-xl border p-3 text-left transition ' +
                    (p.habis
                      ? 'border-stone-200 bg-stone-100 opacity-50'
                      : 'border-stone-200 bg-white active:bg-stone-50')
                  }
                >
                  <p className="text-sm font-semibold text-stone-800">{p.nama}</p>
                  <p className="text-sm text-stone-500 tabular-nums">{rupiah(p.harga)}</p>
                  {p.habis && <p className="text-xs text-stone-400 mt-0.5">Habis</p>}
                </button>
              ))}
            </div>
          </>
        )}
      </main>

      <div className="fixed bottom-16 inset-x-0 z-20 border-t border-stone-200 bg-white px-4 py-3">
        {keranjang.length > 0 ? (
          <>
            <div className="max-h-36 overflow-y-auto divide-y divide-stone-100">
              {keranjang.map((b) => (
                <div key={b.produkId} className="flex items-center gap-2 py-1.5">
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-stone-700 truncate">{b.nama}</p>
                    <p className="text-xs text-stone-400 tabular-nums">
                      {rupiah(b.harga)} × {b.qty}
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={() => setKeranjang(kurangKeranjang(keranjang, b.produkId))}
                    aria-label="Kurangi"
                    className="p-1.5 rounded-md border border-stone-300 text-stone-500"
                  >
                    <Minus size={14} />
                  </button>
                  <button
                    type="button"
                    onClick={() => setKeranjang(naikkanQty(keranjang, b.produkId))}
                    aria-label="Tambah"
                    className="p-1.5 rounded-md border border-stone-300 text-stone-500"
                  >
                    <Plus size={14} />
                  </button>
                  <button
                    type="button"
                    onClick={() => setKeranjang(hapusKeranjang(keranjang, b.produkId))}
                    aria-label="Hapus"
                    className="p-1.5 rounded-md text-red-500 hover:bg-red-50"
                  >
                    <Trash2 size={14} />
                  </button>
                  <p className="w-20 text-right text-sm font-semibold tabular-nums shrink-0">
                    {rupiah(b.harga * b.qty)}
                  </p>
                </div>
              ))}
            </div>
            <div className="mt-2 flex items-center justify-between border-t border-stone-100 pt-2">
              <p className="text-sm text-stone-500">Total · {jumlahItem(keranjang)} item</p>
              <p className="text-lg font-bold tabular-nums">{rupiah(total)}</p>
            </div>
          </>
        ) : (
          <p className="py-2 text-center text-sm text-stone-400">
            Keranjang kosong — ketuk produk untuk menambah.
          </p>
        )}

        <button
          type="button"
          onClick={submit}
          disabled={sibuk || keranjang.length === 0}
          className="mt-2 w-full rounded-lg bg-stone-900 px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-40"
        >
          {sibuk ? 'Membuat pesanan…' : 'Buat pesanan'}
        </button>
      </div>
    </div>
  )
}
