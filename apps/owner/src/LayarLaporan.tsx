import { useEffect, useMemo, useState } from 'react'
import { Banknote, Receipt, TrendingUp, Wallet } from 'lucide-react'
import { ambilLaporan, rentangPeriode, SESI_HABIS, tanggalJamWIB, type Laporan } from './api'
import { rupiah } from './format'

type Periode = 'hari' | '7' | '30'

const HARI_PER_PERIODE: Record<Periode, number> = { hari: 1, '7': 7, '30': 30 }
const LABEL_PERIODE: Record<Periode, string> = { hari: 'Hari ini', '7': '7 hari', '30': '30 hari' }

const KATEGORI_LABEL: Record<string, string> = {
  bahan: 'Bahan baku',
  operasional: 'Operasional',
  gaji: 'Gaji',
  lain: 'Lainnya',
}

export default function LayarLaporan({ onKeluar }: { onKeluar: () => void }) {
  const [laporan, setLaporan] = useState<Laporan | null>(null)
  const [galat, setGalat] = useState('')
  const [periode, setPeriode] = useState<Periode>('7')

  const N = HARI_PER_PERIODE[periode]
  const rentang = useMemo(() => rentangPeriode(N), [N])

  useEffect(() => {
    let batal = false
    ambilLaporan(rentang.mulaiCur, rentang.selesaiCur)
      .then(d => {
        if (!batal) setLaporan(d)
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
  }, [rentang, onKeluar])

  const margin = laporan && Number.isFinite(laporan.margin_percent) ? laporan.margin_percent : 0

  return (
    <div className="max-w-3xl mx-auto px-4 py-5 space-y-4">
      <div>
        <h1 className="text-lg font-bold text-stone-900">Laporan laba rugi</h1>
        <p className="text-xs text-stone-400 mt-0.5">Omzet − harga pokok − pengeluaran · WIB</p>
      </div>

      {galat && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">{galat}</div>
      )}

      {/* — Pilih periode — */}
      <div className="flex gap-1 bg-stone-100 rounded-xl p-1">
        {(['hari', '7', '30'] as Periode[]).map(p => (
          <button
            key={p}
            onClick={() => setPeriode(p)}
            className={`flex-1 py-2 rounded-lg text-sm font-medium transition-colors ${
              periode === p ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'
            }`}
          >
            {LABEL_PERIODE[p]}
          </button>
        ))}
      </div>

      {laporan ? (
        <>
          {/* — Laba bersih (hero) — */}
          <section
            className="text-white rounded-2xl p-5 shadow-sm"
            style={{ background: 'linear-gradient(135deg, var(--color-sage-600), var(--color-sage-800))' }}
          >
            <p className="text-xs font-medium text-sage-100/80">Laba bersih</p>
            <p className="text-3xl font-bold tabular-nums mt-1.5 leading-none">{rupiah(laporan.net)}</p>
            <p className="text-xs text-sage-100/80 mt-2">
              Margin {margin.toFixed(1)}% · Omset {rupiah(laporan.sales.total)}
            </p>
          </section>

          {/* — Rincian — */}
          <div className="grid grid-cols-2 gap-3">
            <Kartu label="Omset" nilai={rupiah(laporan.sales.total)} Ikon={Banknote} />
            <Kartu label="Harga pokok" nilai={rupiah(laporan.cogs)} Ikon={Receipt} />
            <Kartu
              label="Laba kotor"
              nilai={rupiah(laporan.gross_profit)}
              Ikon={TrendingUp}
              sub={`Margin ${margin.toFixed(1)}%`}
            />
            <Kartu
              label="Pengeluaran"
              nilai={rupiah(laporan.expenses.total)}
              Ikon={Wallet}
              sub={`${laporan.expenses.count} catatan`}
            />
          </div>

          {/* — Pengeluaran per kategori — */}
          {laporan.expenses.total > 0 && (
            <section className="bg-white rounded-2xl border border-stone-200">
              <h2 className="text-sm font-semibold text-stone-800 px-4 pt-4">Pengeluaran per kategori</h2>
              <div className="divide-y divide-stone-100 mt-1">
                {Object.entries(laporan.expenses.by_category).map(([kategori, nilai]) => (
                  <div key={kategori} className="flex items-center justify-between px-4 py-3">
                    <span className="text-sm text-stone-600">{KATEGORI_LABEL[kategori] ?? kategori}</span>
                    <span className="text-sm font-semibold text-stone-800 tabular-nums">{rupiah(nilai)}</span>
                  </div>
                ))}
              </div>
            </section>
          )}

          {/* — Selisih kas per shift — */}
          {laporan.shifts.count > 0 && (
            <section className="bg-white rounded-2xl border border-stone-200">
              <div className="flex items-center justify-between px-4 pt-4">
                <h2 className="text-sm font-semibold text-stone-800">Selisih kas (shift)</h2>
                <span
                  className={`text-sm font-bold tabular-nums ${
                    laporan.shifts.total_variance === 0
                      ? 'text-stone-500'
                      : laporan.shifts.total_variance < 0
                        ? 'text-red-700'
                        : 'text-sage-700'
                  }`}
                >
                  {laporan.shifts.total_variance > 0 ? '+' : ''}
                  {rupiah(laporan.shifts.total_variance)}
                </span>
              </div>
              <p className="text-[11px] text-stone-400 px-4 pt-1 pb-3">
                Jumlah selisih kas dari {laporan.shifts.count} shift yang ditutup pada periode ini.
              </p>
              <div className="divide-y divide-stone-100 border-t border-stone-100">
                {laporan.shifts.items.map((s) => (
                  <div key={s.id} className="flex items-center justify-between px-4 py-3">
                    <div>
                      <p className="text-sm text-stone-700">
                        {tanggalJamWIB(s.ditutupPada ?? s.dibukaPada)}
                      </p>
                      <p className="text-xs text-stone-400 mt-0.5">
                        Modal {rupiah(s.modalAwal)}
                        {s.laporan?.kasDihitung != null ? ` · Kas dihitung ${rupiah(s.laporan.kasDihitung)}` : ''}
                      </p>
                    </div>
                    {s.laporan?.selisih != null && (
                      <span
                        className={`text-sm font-semibold tabular-nums ${
                          s.laporan.selisih === 0
                            ? 'text-stone-500'
                            : s.laporan.selisih < 0
                              ? 'text-red-700'
                              : 'text-sage-700'
                        }`}
                      >
                        {s.laporan.selisih > 0 ? '+' : ''}
                        {rupiah(s.laporan.selisih)}
                      </span>
                    )}
                  </div>
                ))}
              </div>
            </section>
          )}

          {/* — Penanda HPP belum lengkap — */}
          {laporan.cogs === 0 && laporan.sales.total > 0 && (
            <div className="bg-sage-50 border border-sage-200 rounded-xl px-4 py-3 text-sm text-sage-700">
              Harga pokok masih Rp 0 — isi harga beli tiap bahan di layar Bahan biar margin-nya akurat.
            </div>
          )}
        </>
      ) : (
        !galat && <div className="py-16 text-center text-sm text-stone-400">Memuat laporan…</div>
      )}
    </div>
  )
}

function Kartu({
  label,
  nilai,
  Ikon,
  sub,
}: {
  label: string
  nilai: string
  Ikon: typeof Wallet
  sub?: string
}) {
  return (
    <div className="bg-white rounded-2xl border border-stone-200 p-3.5 flex flex-col gap-2">
      <div className="flex items-center justify-between">
        <span className="text-[11px] text-stone-400 font-medium">{label}</span>
        <span className="w-7 h-7 rounded-lg bg-sage-50 text-sage-600 flex items-center justify-center">
          <Ikon size={15} strokeWidth={1.75} />
        </span>
      </div>
      <div>
        <span className="text-lg font-bold text-stone-800 tabular-nums leading-none">{nilai}</span>
        {sub && <p className="text-[11px] text-stone-400 mt-1">{sub}</p>}
      </div>
    </div>
  )
}
