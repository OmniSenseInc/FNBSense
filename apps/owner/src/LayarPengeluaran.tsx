import { useEffect, useState } from 'react'
import { Receipt } from 'lucide-react'
import {
  ambilPengeluaran,
  catatPengeluaran,
  SESI_HABIS,
  tanggalJamWIB,
  type Pengeluaran,
} from './api'
import { rupiah } from './format'

const KATEGORI = [
  { nilai: 'bahan', label: 'Bahan baku' },
  { nilai: 'operasional', label: 'Operasional' },
  { nilai: 'gaji', label: 'Gaji' },
  { nilai: 'lain', label: 'Lainnya' },
]

const KATEGORI_LABEL: Record<string, string> = Object.fromEntries(KATEGORI.map(k => [k.nilai, k.label]))

export default function LayarPengeluaran({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Pengeluaran[] | null>(null)
  const [galat, setGalat] = useState('')
  const [kategori, setKategori] = useState('bahan')
  const [nominal, setNominal] = useState('')
  const [catatan, setCatatan] = useState('')
  const [menyimpan, setMenyimpan] = useState(false)

  useEffect(() => {
    let batal = false
    ambilPengeluaran()
      .then(d => {
        if (!batal) setDaftar(d)
      })
      .catch(e => {
        if (!batal) {
          if (e instanceof Error && e.message === SESI_HABIS) onKeluar()
          else setGalat(e instanceof Error ? e.message : 'Gagal memuat.')
        }
      })
    return () => {
      batal = true
    }
  }, [onKeluar])

  const nominalAngka = Number(nominal) || 0

  const simpan = async () => {
    if (nominalAngka <= 0 || menyimpan) return
    setMenyimpan(true)
    try {
      await catatPengeluaran(kategori, Math.round(nominalAngka), catatan.trim() || null)
      setNominal('')
      setCatatan('')
      // Muat ulang daftar supaya yang baru langsung tampil (server urutkan desc).
      const d = await ambilPengeluaran()
      setDaftar(d)
    } catch (e) {
      setGalat(e instanceof Error ? e.message : 'Gagal menyimpan.')
    } finally {
      setMenyimpan(false)
    }
  }

  return (
    <div className="max-w-3xl mx-auto px-4 py-5 space-y-4">
      <h1 className="text-lg font-bold text-stone-900">Pengeluaran</h1>

      {galat && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">{galat}</div>
      )}

      {/* — Form catat — */}
      <section className="bg-white rounded-2xl border border-stone-200 p-4 space-y-3">
        <div>
          <label className="text-xs text-stone-400 font-medium">Kategori</label>
          <select
            value={kategori}
            onChange={e => setKategori(e.target.value)}
            className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm bg-white"
          >
            {KATEGORI.map(k => (
              <option key={k.nilai} value={k.nilai}>
                {k.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="text-xs text-stone-400 font-medium">Nominal (Rp)</label>
          <input
            inputMode="numeric"
            value={nominal}
            onChange={e => setNominal(e.target.value.replace(/[^0-9]/g, ''))}
            placeholder="0"
            className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm"
          />
        </div>
        <div>
          <label className="text-xs text-stone-400 font-medium">Catatan (opsional)</label>
          <input
            value={catatan}
            onChange={e => setCatatan(e.target.value)}
            placeholder="mis. beli kopi mingguan"
            className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm"
          />
        </div>
        <button
          onClick={simpan}
          disabled={nominalAngka <= 0 || menyimpan}
          className="w-full py-2.5 rounded-xl bg-sage-600 text-white text-sm font-semibold disabled:opacity-40"
        >
          {menyimpan ? 'Menyimpan…' : 'Catat pengeluaran'}
        </button>
      </section>

      {/* — Daftar terakhir — */}
      {daftar ? (
        daftar.length > 0 ? (
          <section className="bg-white rounded-2xl border border-stone-200">
            <h2 className="text-sm font-semibold text-stone-800 px-4 pt-4">Terakhir</h2>
            <div className="divide-y divide-stone-100 mt-1">
              {daftar.map(p => (
                <div key={p.id} className="flex items-center gap-3 px-4 py-3">
                  <span className="w-9 h-9 rounded-xl bg-sage-50 text-sage-600 flex items-center justify-center shrink-0">
                    <Receipt size={16} strokeWidth={1.75} />
                  </span>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-stone-700 truncate">
                      {p.note || (KATEGORI_LABEL[p.category] ?? p.category)}
                    </p>
                    <p className="text-[11px] text-stone-400 tabular-nums">
                      {KATEGORI_LABEL[p.category] ?? p.category} · {tanggalJamWIB(p.spent_at)}
                    </p>
                  </div>
                  <span className="text-sm font-semibold text-stone-800 tabular-nums shrink-0">{rupiah(p.amount)}</span>
                </div>
              ))}
            </div>
          </section>
        ) : (
          <p className="text-sm text-stone-400 text-center py-8">Belum ada pengeluaran tercatat.</p>
        )
      ) : (
        !galat && <div className="py-16 text-center text-sm text-stone-400">Memuat…</div>
      )}
    </div>
  )
}
