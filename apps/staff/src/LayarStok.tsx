import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ambilStok, opnameBahan, peranSaya, restokBahan, SESI_HABIS, type Stok } from './api'
import { keJumlah, tanggalJam } from './format'

/**
 * Saldo stok bahan.
 *
 * TIDAK dipolling, beda dari antrean dan dapur. Kedua layar itu menunggu
 * sesuatu datang sendiri; layar ini DIDATANGI untuk menjawab satu pertanyaan
 * ("susunya masih cukup?") lalu ditinggalkan. Memolling berarti membebani
 * Inventory dan Catalog tiap lima detik demi angka yang bergerak beberapa kali
 * sejam. Tombol muat ulang menutup sisanya.
 *
 * Kasir boleh melihat, tak boleh mengubah — restock dan opname `role:owner` di
 * Inventory. Layar ini memang tak punya tombol ubah sama sekali, tapi yang
 * menjaganya tetap server.
 */
export default function LayarStok({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Stok[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  /** Dinaikkan tombol muat ulang DAN tiap perubahan stok berhasil. */
  const [versi, setVersi] = useState(0)
  /** Baris yang sedang diubah, `null` kalau tak ada. */
  const [aksi, setAksi] = useState<{ id: string; mode: 'restock' | 'opname' } | null>(null)
  const [nilai, setNilai] = useState('')

  // Tombolnya disembunyikan dari kasir, dan itu SEMATA supaya ia tak menawarkan
  // pintu yang pasti terkunci — penjaganya tetap `role:owner` di Inventory.
  const owner = peranSaya() === 'owner'

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    setSibuk(true)

    ambilStok()
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
        setGalat(err instanceof Error ? err.message : 'Gagal memuat stok.')
      })
      .finally(() => {
        if (!batal) setSibuk(false)
      })

    return () => {
      batal = true
    }
  }, [versi])

  const kirim = (stok: Stok) => {
    const jumlah = keJumlah(nilai)
    if (jumlah === null) {
      setGalat('Isi angka saja. Titik hanya untuk desimal (mis. 1.5), bukan pemisah ribuan.')
      return
    }
    if (aksi?.mode === 'restock' && jumlah === 0) {
      setGalat('Barang masuk harus lebih dari nol.')
      return
    }

    setSibuk(true)
    setGalat(null)
    const kerja =
      aksi?.mode === 'restock' ? restokBahan(stok.id, jumlah) : opnameBahan(stok.id, jumlah)

    kerja
      .then(() => {
        setAksi(null)
        setNilai('')
        // Saldo baru dibaca ULANG dari server, tidak dihitung di sini: opname
        // mengganti angka sementara restock menambahnya, dan layar yang ikut
        // menghitung akan menyimpang diam-diam dari ledger yang sebenarnya.
        setVersi((v) => v + 1)
      })
      .catch((err: unknown) => {
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal menyimpan. Coba lagi.')
        setSibuk(false)
      })
  }

  const bukaAksi = (id: string, mode: 'restock' | 'opname') => {
    setAksi({ id, mode })
    setNilai('')
    setGalat(null)
  }

  // Nama hilang = Catalog tak terjangkau saat Inventory menyusun balasan.
  // Diberitahukan SEKALI di atas, bukan diulang di tiap baris: kasir cuma perlu
  // tahu kenapa sebagian barisnya tak bernama, bukan diingatkan lima belas kali.
  const adaNamaHilang = daftar?.some((s) => s.nama === null) ?? false

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Stok</h1>
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

        {adaNamaHilang && (
          <p className="mb-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            Sebagian nama bahan sedang tak bisa diambil. Angkanya tetap benar.
          </p>
        )}

        {daftar !== null && daftar.length === 0 && (
          <p className="py-16 text-center text-sm text-slate-600">
            {/* Jujur soal batasnya: tombol "Barang masuk" menempel pada baris
                yang sudah ada, jadi saat daftarnya kosong memang tak ada yang
                bisa ditekan di layar ini. Bahan pertama lahir di Catalog. */}
            Belum ada bahan yang distok. Bahannya dibuat dulu di menu, baru saldonya bisa diisi
            dari sini.
          </p>
        )}

        <ul className="space-y-2">
          {daftar?.map((stok) => {
            // Menipis hanya kalau batasnya memang disetel. minimum 0 berarti
            // "tak diawasi", dan qty 0 <= 0 akan membuat SETIAP bahan tanpa
            // batas menyala oranye — peringatan yang menyala di mana-mana sama
            // saja dengan tak ada peringatan.
            const menipis =
              stok.minimum !== null &&
              stok.minimum > 0 &&
              !Number.isNaN(stok.qty) &&
              stok.qty <= stok.minimum

            return (
              <li
                key={stok.id}
                className={`rounded-md border p-3 ${
                  menipis ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'
                }`}
              >
                <div className="flex items-baseline justify-between gap-3">
                  <p className="text-sm font-semibold">
                    {/* Potongan id, bukan UUID penuh: yang penuh selebar layar
                        dan mendorong angkanya keluar. Delapan huruf cukup untuk
                        membedakan satu baris dari baris lain. */}
                    {stok.nama ?? (
                      <span className="font-mono text-slate-500">Bahan {stok.id.slice(0, 8)}</span>
                    )}
                  </p>
                  {/* NaN ditampilkan sebagai "—", bukan 0. Nol berbunyi "habis",
                      dan itu kalimat yang membuat orang berhenti menjual sesuatu
                      yang sebenarnya masih ada. */}
                  <p className="shrink-0 text-lg font-bold tabular-nums">
                    {Number.isNaN(stok.qty) ? '—' : stok.qty}
                  </p>
                </div>

                <div className="mt-1 flex items-baseline justify-between gap-3">
                  <p className="text-xs text-slate-500 tabular-nums">{tanggalJam(stok.diperbarui)}</p>
                  {menipis && (
                    <span className="text-xs font-semibold text-amber-800">
                      Menipis · batas {stok.minimum}
                    </span>
                  )}
                </div>

                {owner && aksi?.id !== stok.id && (
                  <div className="mt-2 flex gap-2">
                    <button
                      type="button"
                      onClick={() => bukaAksi(stok.id, 'restock')}
                      className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs"
                    >
                      Barang masuk
                    </button>
                    <button
                      type="button"
                      onClick={() => bukaAksi(stok.id, 'opname')}
                      className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs"
                    >
                      Opname
                    </button>
                  </div>
                )}

                {owner && aksi?.id === stok.id && (
                  <form
                    onSubmit={(e) => {
                      e.preventDefault()
                      kirim(stok)
                    }}
                    className="mt-2 rounded-md border border-slate-300 bg-slate-50 p-2"
                  >
                    <label htmlFor={`nilai-${stok.id}`} className="block text-xs">
                      {/* Dua kalimat yang sengaja berbeda tajam: yang satu
                          MENAMBAH, yang lain MENGGANTI. Satu label generik
                          seperti "jumlah" adalah cara paling rapi membuat owner
                          mengetik 5 karung yang baru datang ke dalam kotak yang
                          artinya "seluruh isi gudang cuma 5". */}
                      {aksi.mode === 'restock'
                        ? 'Berapa yang masuk? (ditambahkan ke saldo)'
                        : 'Hasil hitung fisik (mengganti saldo)'}
                    </label>
                    <div className="mt-1 flex gap-2">
                      <input
                        id={`nilai-${stok.id}`}
                        type="text"
                        inputMode="decimal"
                        value={nilai}
                        onChange={(e) => setNilai(e.target.value)}
                        autoFocus
                        className="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm tabular-nums"
                      />
                      <button
                        type="submit"
                        disabled={sibuk}
                        className="shrink-0 rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                      >
                        Simpan
                      </button>
                      <button
                        type="button"
                        onClick={() => setAksi(null)}
                        className="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs"
                      >
                        Batal
                      </button>
                    </div>
                  </form>
                )}
              </li>
            )
          })}
        </ul>
      </main>
    </div>
  )
}
