import { useState } from 'react'
import type { Bahan, BarisResep } from './api'
import { keJumlah, rupiah } from './format'

/**
 * Resep satu produk: bahan apa saja, berapa banyak per porsi.
 *
 * Menumpang di layar menu, bukan jadi alamat sendiri, karena resep tak pernah
 * berdiri lepas dari produknya — owner yang baru membuat "Kopi Susu" menetapkan
 * takarannya di detik yang sama, bukan setelah memilih ulang produk itu di layar
 * lain.
 *
 * Harga beli tiap bahan ikut ditampilkan DAN bisa diubah di sini, di baris
 * bahannya sendiri. Tanpa ini owner harus melompat ke layar Bahan untuk
 * mengisi harga — dua layar terpisah untuk satu pekerjaan yang sama ("berapa
 * modal produk ini"), dan HPP di layar menu tak pernah kunjung kehitung.
 *
 * Semua tulis-menulis dikerjakan induknya: panel ini tak memanggil server dan tak
 * memuat ulang apa pun. Satu tempat yang memegang "sedang sibuk" dan "muat
 * ulang" lebih mudah dipercaya daripada dua yang harus disetel sejalan.
 */
export default function PanelResep({
  namaProduk,
  baris,
  bahan,
  sibuk,
  onTambah,
  onUbah,
  onHapus,
  onUbahHargaBeli,
}: {
  namaProduk: string
  baris: BarisResep[]
  bahan: Bahan[]
  sibuk: boolean
  onTambah: (bahanId: string, takaran: number) => void
  onUbah: (id: string, takaran: number) => void
  onHapus: (id: string) => void
  onUbahHargaBeli: (bahanId: string, harga: number) => void
}) {
  const [bahanBaru, setBahanBaru] = useState('')
  const [takaranBaru, setTakaranBaru] = useState('')
  /** Takaran yang sedang diketik ulang, per id baris. Kosong = belum disentuh. */
  const [draf, setDraf] = useState<Record<string, string>>({})
  /** Harga beli yang sedang diketik ulang, per bahanId. Kosong = belum disentuh. */
  const [editHarga, setEditHarga] = useState<Record<string, string>>({})
  const [galat, setGalat] = useState<string | null>(null)

  const cari = (id: string): Bahan | undefined => bahan.find((b) => b.id === id)

  /** Nama bahan, atau potongan id kalau daftarnya tak memuatnya — sepola layar stok. */
  const namaBahan = (id: string): string => cari(id)?.nama ?? `Bahan ${id.slice(0, 8)}…`

  // Bahan yang sudah dipakai dibuang dari pilihan: DB menolak bahan yang sama
  // dua kali dalam satu produk (unique product_id+ingredient_id), jadi
  // menawarkannya berarti menjanjikan galat 422 yang sudah pasti.
  const belumDipakai = bahan.filter((b) => !baris.some((r) => r.bahanId === b.id))

  /** Teks -> takaran yang sah, atau null sambil memasang pesannya sendiri. */
  const bacaTakaran = (teks: string): number | null => {
    const angka = keJumlah(teks)
    if (angka === null) {
      setGalat('Takaran harus angka. Titik hanya untuk desimal (mis. 1.5), bukan pemisah ribuan.')
      return null
    }
    // Nol lolos regex tapi ditolak DB (CHECK qty_per_unit > 0). Ditahan di sini
    // supaya owner membaca alasannya, bukan "Ada isian yang ditolak server".
    if (angka === 0) {
      setGalat('Takaran harus lebih dari nol.')
      return null
    }

    return angka
  }

  const submitTambah = (e: React.FormEvent) => {
    e.preventDefault()
    if (bahanBaru === '') {
      setGalat('Pilih bahannya dulu.')
      return
    }
    const angka = bacaTakaran(takaranBaru)
    if (angka === null) return

    setGalat(null)
    setBahanBaru('')
    setTakaranBaru('')
    onTambah(bahanBaru, angka)
  }

  const simpanTakaran = (r: BarisResep) => {
    const angka = bacaTakaran(draf[r.id] ?? '')
    if (angka === null) return

    setGalat(null)
    // Draf dibuang supaya barisnya kembali menampilkan nilai dari server. Kalau
    // dipertahankan, angka yang gagal disimpan akan tetap terbaca seolah sudah
    // tersimpan.
    setDraf(({ [r.id]: _dibuang, ...sisa }) => sisa)
    onUbah(r.id, angka)
  }

  /** Simpan harga beli yang sedang diketik. Kosong dibaca 0 = "belum dihargai". */
  const simpanHarga = (bahanId: string) => {
    const angka = Number(editHarga[bahanId]) || 0
    setEditHarga(({ [bahanId]: _dibuang, ...sisa }) => sisa)
    onUbahHargaBeli(bahanId, angka)
  }

  const nilaiBaris = (r: BarisResep): string =>
    draf[r.id] ?? (Number.isFinite(r.takaran) ? String(r.takaran) : '')

  return (
    <div className="mt-2 rounded-md border border-stone-300 bg-stone-50 p-3">
      <h3 className="text-xs font-semibold">Resep {namaProduk}</h3>

      {galat && (
        <p role="alert" className="mt-2 rounded-md border border-red-300 bg-red-50 p-2 text-xs">
          {galat}
        </p>
      )}

      {baris.length === 0 && (
        <p className="mt-2 rounded-md border border-sage-300 bg-sage-50 p-2 text-xs text-sage-900">
          Belum ada resep. Produk ini tetap bisa dipesan walau bahannya habis — stok cuma diperiksa
          untuk produk yang punya resep.
        </p>
      )}

      <ul className="mt-2 space-y-2">
        {baris.map((r) => {
          const bahanIni = cari(r.bahanId)

          return (
            <li key={r.id} className="flex items-center gap-2">
              <div className="min-w-0 flex-1">
                <p className="truncate text-xs font-medium">{namaBahan(r.bahanId)}</p>
                {editHarga[r.bahanId] !== undefined ? (
                  <div className="mt-1 flex items-center gap-1">
                    <input
                      autoFocus
                      inputMode="numeric"
                      value={editHarga[r.bahanId]}
                      onChange={(e) =>
                        setEditHarga((d) => ({
                          ...d,
                          [r.bahanId]: e.target.value.replace(/[^0-9]/g, ''),
                        }))
                      }
                      placeholder="Harga beli (Rp)"
                      aria-label={`Harga beli ${namaBahan(r.bahanId)}`}
                      className="w-24 rounded-md border border-stone-300 px-1.5 py-0.5 text-xs tabular-nums"
                    />
                    <button
                      type="button"
                      onClick={() => simpanHarga(r.bahanId)}
                      disabled={sibuk}
                      className="rounded-md bg-stone-900 px-2 py-0.5 text-xs font-semibold text-white disabled:opacity-50"
                    >
                      Simpan harga
                    </button>
                    <button
                      type="button"
                      onClick={() => setEditHarga(({ [r.bahanId]: _dibuang, ...sisa }) => sisa)}
                      className="rounded-md border border-stone-300 px-2 py-0.5 text-xs"
                    >
                      Batal
                    </button>
                  </div>
                ) : (
                  <button
                    type="button"
                    onClick={() =>
                      setEditHarga((d) => ({
                        ...d,
                        [r.bahanId]:
                          bahanIni && bahanIni.hargaBeli > 0 ? String(bahanIni.hargaBeli) : '',
                      }))
                    }
                    className="mt-0.5 text-left text-xs text-stone-500 underline decoration-dotted"
                  >
                    {bahanIni && bahanIni.hargaBeli > 0
                      ? `Beli ${rupiah(bahanIni.hargaBeli)}/${bahanIni.satuan}`
                      : 'Harga beli belum diisi — ketuk untuk isi'}
                  </button>
                )}
              </div>
              <label htmlFor={`takaran-${r.id}`} className="sr-only">
                Takaran {namaBahan(r.bahanId)}
              </label>
              <input
                id={`takaran-${r.id}`}
                type="text"
                inputMode="decimal"
                value={nilaiBaris(r)}
                onChange={(e) => setDraf((d) => ({ ...d, [r.id]: e.target.value }))}
                className="w-20 rounded-md border border-stone-300 px-2 py-1 text-xs tabular-nums"
              />
              <span className="w-8 shrink-0 text-xs text-stone-500">{bahanIni?.satuan}</span>
              <button
                type="button"
                onClick={() => simpanTakaran(r)}
                // Tombol mati selama takarannya belum disentuh: menyimpan angka
                // yang sama persis cuma menambah satu perjalanan ke server.
                disabled={sibuk || draf[r.id] === undefined}
                className="shrink-0 rounded-md border border-stone-300 px-2 py-1 text-xs disabled:opacity-50"
              >
                Simpan
              </button>
              <button
                type="button"
                onClick={() => onHapus(r.id)}
                disabled={sibuk}
                aria-label={`Hapus ${namaBahan(r.bahanId)} dari resep`}
                className="shrink-0 rounded-md border border-stone-300 px-2 py-1 text-xs disabled:opacity-50"
              >
                ×
              </button>
            </li>
          )
        })}
      </ul>

      {bahan.length === 0 ? (
        <p className="mt-3 text-xs text-stone-600">
          Belum ada bahan sama sekali. Daftarkan bahannya dulu di halaman Bahan.
        </p>
      ) : (
        <form onSubmit={submitTambah} className="mt-3 flex items-center gap-2">
          <label htmlFor={`bahan-baru-${namaProduk}`} className="sr-only">
            Bahan untuk {namaProduk}
          </label>
          <select
            id={`bahan-baru-${namaProduk}`}
            value={bahanBaru}
            onChange={(e) => setBahanBaru(e.target.value)}
            className="min-w-0 flex-1 rounded-md border border-stone-300 px-2 py-1 text-xs"
          >
            <option value="">— pilih bahan —</option>
            {belumDipakai.map((b) => (
              <option key={b.id} value={b.id}>
                {b.nama}
              </option>
            ))}
          </select>
          <label htmlFor={`takaran-baru-${namaProduk}`} className="sr-only">
            Takaran untuk {namaProduk}
          </label>
          <input
            id={`takaran-baru-${namaProduk}`}
            type="text"
            inputMode="decimal"
            value={takaranBaru}
            onChange={(e) => setTakaranBaru(e.target.value)}
            placeholder="18"
            className="w-20 rounded-md border border-stone-300 px-2 py-1 text-xs tabular-nums"
          />
          <button
            type="submit"
            disabled={sibuk || belumDipakai.length === 0}
            className="shrink-0 rounded-md border border-stone-300 px-2 py-1 text-xs disabled:opacity-50"
          >
            Tambah bahan
          </button>
        </form>
      )}
    </div>
  )
}
