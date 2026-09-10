import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import {
  FlaskConical,
  LayoutGrid,
  Package,
  Settings,
  Tag,
  TrendingUp,
  Users,
  UtensilsCrossed,
} from 'lucide-react'
import {
  ambilPesananDari,
  awalHariWIB,
  kunciTanggalWIB,
  peranSaya,
  rentangPeriode,
  ringkasPesanan,
  SESI_HABIS,
  tanggalJamWIB,
  tanggalLengkapWIB,
  sapaanWIB,
  topDariPesanan,
  trenDariPesanan,
  type Pesanan,
  type TrenHarian,
} from './api'
import { labelMeja, rupiah } from './format'

/*
 * Cermin token palet TINTA di index.css. SVG memakai hex langsung (bukan var())
 * supaya warnanya PASTI ter-render — atribut presentasi SVG tidak selalu
 * melarutkan var() CSS dengan andal di semua browser.
 */
const INK = '#17150f'
const LINE = '#d9d5c8'
const LINE_SOFT = '#e6e2d6'
const FAINT = '#a6a194'

/**
 * Jam WIB saat ini, bentuk \"HH.MM\" (mis. \"20.21\"). Penanda zona waktu yang
 * HIDUP di grafik — bukan label statis — supaya pembaca tahu angka grafik
 * memang mengikuti WIB (Asia/Jakarta), bukan jam perangkat.
 */
function jamWIB(d: Date): string {
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d)
}

/** Jam WIB yang terus berjalan. Komponen terpisah supaya tick-nya tak merender seluruh dashboard. */
function JamWIB() {
  const [sekarang, setSekarang] = useState(() => new Date())

  useEffect(() => {
    const id = setInterval(() => setSekarang(new Date()), 30_000)
    return () => clearInterval(id)
  }, [])

  return (
    <span className="text-[11px] font-medium text-muted tabular-nums">
      {jamWIB(sekarang)} WIB
    </span>
  )
}

type Periode = 'hari' | '7' | '30'

const HARI_PER_PERIODE: Record<Periode, number> = { hari: 1, '7': 7, '30': 30 }

const LABEL_PERIODE: Record<Periode, string> = {
  hari: 'hari ini',
  '7': '7 hari terakhir',
  '30': '30 hari terakhir',
}

const PILIHAN_PERIODE: { k: Periode; label: string }[] = [
  { k: 'hari', label: 'Hari ini' },
  { k: '7', label: '7 hari' },
  { k: '30', label: '30 hari' },
]

export default function LayarDashboard({ onKeluar }: { onKeluar: () => void }) {
  const [pesanan, setPesanan] = useState<Pesanan[] | null>(null)
  const [galat, setGalat] = useState('')
  const [periode, setPeriode] = useState<Periode>('7')

  const N = HARI_PER_PERIODE[periode]
  // Ambil 2×N hari: cukup untuk periode saat ini DAN periode pembandingnya.
  const sejak = useMemo(() => awalHariWIB(2 * N - 1), [N])
  const rentang = useMemo(() => rentangPeriode(N), [N])

  useEffect(() => {
    let batal = false
    ambilPesananDari(sejak)
      .then(d => {
        if (!batal) setPesanan(d)
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
  }, [sejak, onKeluar])

  const cur = useMemo(
    () => (pesanan ? ringkasPesanan(pesanan, rentang.mulaiCur, rentang.selesaiCur) : null),
    [pesanan, rentang],
  )
  const prev = useMemo(
    () => (pesanan ? ringkasPesanan(pesanan, rentang.mulaiPrev, rentang.selesaiPrev) : null),
    [pesanan, rentang],
  )
  const tren = useMemo(() => (pesanan ? trenDariPesanan(pesanan, N) : []), [pesanan, N])

  const top = useMemo(() => {
    if (!pesanan) return []
    const dalam = pesanan.filter(p => {
      const t = kunciTanggalWIB(p.waktuBayar)
      return t !== null && t >= rentang.mulaiCur && t <= rentang.selesaiCur
    })
    return topDariPesanan(dalam)
  }, [pesanan, rentang])

  const aktivitas = useMemo(() => {
    if (!pesanan) return []
    return pesanan
      .filter(p => {
        const t = kunciTanggalWIB(p.waktuBayar)
        return t !== null && t >= rentang.mulaiCur && t <= rentang.selesaiCur
      })
      .sort((a, b) => new Date(b.waktuBayar ?? 0).getTime() - new Date(a.waktuBayar ?? 0).getTime())
      .slice(0, 5)
  }, [pesanan, rentang])

  const rataCur = cur && cur.jumlah > 0 ? Math.round(cur.total / cur.jumlah) : 0
  const rataPrev = prev && prev.jumlah > 0 ? Math.round(prev.total / prev.jumlah) : 0

  const label = (teks: string) => (
    <h2 className="text-[11px] font-semibold uppercase tracking-wider text-muted">{teks}</h2>
  )

  return (
    <div className="max-w-3xl mx-auto px-5 py-6 space-y-7">
      {/* — Sapaan & tanggal WIB — */}
      <div>
        <p className="text-xl font-bold tracking-tight text-ink">{sapaanWIB()}</p>
        <p className="text-xs text-muted mt-1">{tanggalLengkapWIB()}</p>
      </div>

      {galat && (
        <div className="border border-brick rounded-md px-4 py-3 text-sm text-brick">{galat}</div>
      )}

      {/* — Pilih periode — */}
      <div className="flex border border-ink rounded-md overflow-hidden">
        {PILIHAN_PERIODE.map((p, i) => (
          <button
            key={p.k}
            onClick={() => setPeriode(p.k)}
            className={`flex-1 py-2.5 text-sm font-medium transition-colors ${
              i > 0 ? 'border-l border-line' : ''
            } ${periode === p.k ? 'bg-ink text-paper' : 'text-muted hover:text-ink'}`}
          >
            {p.label}
          </button>
        ))}
      </div>

      {cur && prev ? (
        <>
          {/* — Omset — */}
          <section>
            {label(`Omset · ${LABEL_PERIODE[periode]}`)}
            <div className="flex items-end justify-between gap-3 mt-3">
              <p className="text-4xl font-bold tabular-nums tracking-tight leading-none text-ink">
                {rupiah(cur.total)}
              </p>
              <BadgeUbah cur={cur.total} prev={prev.total} />
            </div>
            <div className="border-t border-dashed border-line mt-4" />
          </section>

          {/* — Transaksi & rata-rata — */}
          <section className="grid grid-cols-2 border-y border-ink">
            <div className="py-4">
              <div className="text-[11px] font-semibold uppercase tracking-wider text-muted">Transaksi</div>
              <div className="text-2xl font-bold tabular-nums mt-2">{cur.jumlah}</div>
              <div className="mt-1">
                <BadgeUbah cur={cur.jumlah} prev={prev.jumlah} />
              </div>
            </div>
            <div className="py-4 pl-6 border-l border-line">
              <div className="text-[11px] font-semibold uppercase tracking-wider text-muted">Rata-rata</div>
              <div className="text-2xl font-bold tabular-nums mt-2">{rupiah(rataCur)}</div>
              <div className="mt-1">
                <BadgeUbah cur={rataCur} prev={rataPrev} />
              </div>
            </div>
          </section>

          {/* — Grafik garis — */}
          <section>
            <div className="flex items-center justify-between mb-3">
              {label('Pendapatan harian')}
              <JamWIB />
            </div>
            <GrafikGaris tren={tren} />
          </section>

          {/* — Metode pembayaran — */}
          <section>
            {label('Metode pembayaran')}
            <div className="mt-3">
              <BarPembayaran tunai={cur.tunai} qris={cur.qris} />
              <div className="grid grid-cols-2 gap-4 mt-3">
                <Legenda
                  hatch={false}
                  label="Tunai"
                  nilai={rupiah(cur.tunai)}
                  pct={pctDari(cur.tunai, cur.tunai + cur.qris)}
                />
                <Legenda
                  hatch
                  label="QRIS"
                  nilai={rupiah(cur.qris)}
                  pct={pctDari(cur.qris, cur.tunai + cur.qris)}
                />
              </div>
            </div>
          </section>

          {/* — Produk terlaris — */}
          {top.length > 0 && (
            <section>
              {label('Produk terlaris')}
              <div className="divide-y divide-line-soft mt-1">
                {top.slice(0, 5).map((p, i) => (
                  <BarisProduk
                    key={p.produk}
                    produk={p.produk}
                    jumlah={p.jumlah}
                    total={p.total}
                    urutan={i}
                    maks={top[0]?.total ?? 1}
                  />
                ))}
              </div>
            </section>
          )}

          {/* — Aktivitas terbaru — */}
          {aktivitas.length > 0 && (
            <section>
              <div className="flex items-center justify-between">
                {label('Aktivitas terbaru')}
                <Link to="/laporan" className="text-xs font-medium text-ink underline underline-offset-2">
                  Lihat semua
                </Link>
              </div>
              <div className="divide-y divide-dashed divide-line mt-2">
                {aktivitas.map(p => (
                  <BarisAktivitas key={p.id} p={p} />
                ))}
              </div>
            </section>
          )}
        </>
      ) : (
        !galat && <div className="py-16 text-center text-sm text-muted">Memuat beranda…</div>
      )}

      {/* — Akses cepat — */}
      <AksesCepat />
    </div>
  )
}

function BadgeUbah({ cur, prev }: { cur: number; prev: number }) {
  const p = prev === 0 ? null : ((cur - prev) / prev) * 100
  if (p === null) {
    return <span className="text-xs font-medium text-faint">—</span>
  }
  const naik = p > 0.05
  const turun = p < -0.05
  const tanda = naik ? '↑' : turun ? '↓' : ''
  const teks = `${tanda}${tanda ? ' ' : ''}${Math.abs(p).toFixed(0)}%`
  const cls = naik ? 'text-ink' : turun ? 'text-brick' : 'text-muted'
  return (
    <span className={`inline-flex items-center text-xs font-semibold tabular-nums whitespace-nowrap ${cls}`}>
      {teks}
    </span>
  )
}

function GrafikGaris({ tren }: { tren: TrenHarian[] }) {
  const LEBAR = 360
  const TINGGI = 150
  const kiri = 40
  const kanan = 12
  const atas = 14
  const bawah = 24
  const plotW = LEBAR - kiri - kanan
  const plotH = TINGGI - atas - bawah
  const n = tren.length
  const maks = Math.max(...tren.map(d => d.total), 1)
  const x = (i: number) => (n <= 1 ? kiri + plotW / 2 : kiri + (i / (n - 1)) * plotW)
  const y = (v: number) => atas + (1 - v / maks) * plotH
  const y0 = atas + plotH
  const titik = tren.map((d, i) => `${x(i).toFixed(1)},${y(d.total).toFixed(1)}`).join(' ')
  const langkah = Math.max(1, Math.ceil(n / 6))

  return (
    <svg viewBox={`0 0 ${LEBAR} ${TINGGI}`} className="w-full h-auto" role="img" aria-label="Grafik garis pendapatan harian">
      {/* garis bantu horizontal */}
      {[1 / 3, 2 / 3, 1].map((frac, i) => {
        const gy = atas + (1 - frac) * plotH
        return (
          <g key={i}>
            <line x1={kiri} x2={LEBAR - kanan} y1={gy} y2={gy} stroke={LINE_SOFT} strokeWidth="1" strokeDasharray="2 3" />
            <text x={kiri - 5} y={gy + 3} textAnchor="end" fontSize="8" fill={FAINT}>
              {ringkas(maks * frac)}
            </text>
          </g>
        )
      })}

      {/* garis dasar */}
      <line x1={kiri} x2={LEBAR - kanan} y1={y0} y2={y0} stroke={INK} strokeWidth="1" />

      {/* garis data (butuh minimal 2 titik) */}
      {n > 1 && (
        <polyline
          points={titik}
          fill="none"
          stroke={INK}
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      )}

      {/* titik + tooltip */}
      {tren.map((d, i) => (
        <g key={d.tanggal}>
          <circle cx={x(i)} cy={y(d.total)} r="10" fill="transparent">
            <title>{`${d.labelPanjang} · ${rupiah(d.total)} · ${d.jumlah} transaksi`}</title>
          </circle>
          <circle cx={x(i)} cy={y(d.total)} r={d.total > 0 ? 2.75 : 1.5} fill={d.total > 0 ? INK : LINE} />
        </g>
      ))}

      {/* label sumbu-x */}
      {tren.map((d, i) =>
        i % langkah === 0 || i === n - 1 ? (
          <text key={`l-${d.tanggal}`} x={x(i)} y={TINGGI - 7} textAnchor="middle" fontSize="9" fill={FAINT}>
            {d.label}
          </text>
        ) : null,
      )}
    </svg>
  )
}

function BarPembayaran({ tunai, qris }: { tunai: number; qris: number }) {
  const total = tunai + qris
  const pctTunai = total > 0 ? (tunai / total) * 100 : 0
  return (
    <div className="h-4 border border-ink flex overflow-hidden" role="img" aria-label="Perbandingan tunai dan QRIS">
      <div className="bg-ink" style={{ width: `${pctTunai}%` }} />
      <div
        className="flex-1"
        style={{ backgroundImage: 'repeating-linear-gradient(45deg, #17150f 0 2px, transparent 2px 5px)' }}
      />
    </div>
  )
}

function Legenda({ hatch, label, nilai, pct }: { hatch: boolean; label: string; nilai: string; pct: number }) {
  return (
    <div>
      <div className="flex items-center gap-2">
        <span
          className="w-3 h-3 shrink-0"
          style={
            hatch
              ? { backgroundImage: 'repeating-linear-gradient(45deg, #17150f 0 1.5px, transparent 1.5px 4px)', border: '1px solid #17150f' }
              : { background: INK }
          }
        />
        <span className="text-[11px] font-semibold uppercase tracking-wider text-muted">{label}</span>
      </div>
      <p className="text-base font-bold tabular-nums mt-1.5">{nilai}</p>
      <p className="text-xs text-muted mt-0.5">{pct}% dari omset</p>
    </div>
  )
}

function BarisProduk({
  produk,
  jumlah,
  total,
  urutan,
  maks,
}: {
  produk: string
  jumlah: number
  total: number
  urutan: number
  maks: number
}) {
  const pct = maks > 0 ? Math.max(0, Math.round((total / maks) * 100)) : 0
  return (
    <div className="py-3">
      <div className="flex items-baseline gap-3">
        <span className="text-xs font-semibold tabular-nums text-muted w-4">{urutan + 1}</span>
        <span className="flex-1 text-sm font-medium text-ink truncate">{produk}</span>
        <span className="text-xs tabular-nums text-muted">{jumlah}×</span>
        <span className="text-sm font-semibold tabular-nums text-ink min-w-[96px] text-right">{rupiah(total)}</span>
      </div>
      <div className="mt-2 ml-7 h-[3px] bg-ink" style={{ width: `${pct}%` }} />
    </div>
  )
}

function BarisAktivitas({ p }: { p: Pesanan }) {
  const judul = labelMeja(p.meja, p.tipe) ?? (p.customer_name || 'Bawa pulang')
  const cara = p.caraBayar === 'cash' ? 'Tunai' : p.caraBayar === 'qris_static' ? 'QRIS' : '—'
  return (
    <div className="flex items-center gap-3 py-3">
      <div className="flex-1 min-w-0">
        <p className="text-sm text-ink truncate">{judul}</p>
        <p className="text-[11px] text-muted tabular-nums mt-0.5">
          {tanggalJamWIB(p.waktuBayar)} · {p.order_number}
        </p>
      </div>
      <div className="text-right shrink-0">
        <p className="text-sm font-semibold text-ink tabular-nums">{rupiah(p.grand_total)}</p>
        <p className="text-[11px] font-medium text-muted">{cara}</p>
      </div>
    </div>
  )
}

function AksesCepat() {
  // Semua tautan di sini WAJIB mengarah ke layar milik app OWNER. Kasir, dapur,
  // riwayat, dan nota adalah layar POS (app kasir) — shortcut ke sana di dashboard
  // owner cuma menghasilkan tombol mati. Dan shortcut mengikuti peran: manager
  // cuma boleh MELIHAT, jadi jangan tawarkan layar tulis milik owner.
  const owner = peranSaya() === 'owner'
  const tautan = owner
    ? [
        { ke: '/laporan', label: 'Laporan', Ikon: TrendingUp },
        { ke: '/menu', label: 'Menu', Ikon: UtensilsCrossed },
        { ke: '/meja', label: 'Meja', Ikon: LayoutGrid },
        { ke: '/promo', label: 'Promo', Ikon: Tag },
        { ke: '/staf', label: 'Karyawan', Ikon: Users },
        { ke: '/setelan', label: 'Pengaturan', Ikon: Settings },
      ]
    : [
        { ke: '/laporan', label: 'Laporan', Ikon: TrendingUp },
        { ke: '/menu', label: 'Menu', Ikon: UtensilsCrossed },
        { ke: '/stok', label: 'Stok', Ikon: Package },
        { ke: '/bahan', label: 'Bahan', Ikon: FlaskConical },
      ]
  return (
    <section>
      <h2 className="text-[11px] font-semibold uppercase tracking-wider text-muted mb-2">Akses cepat</h2>
      <div className="grid grid-cols-3 gap-2">
        {tautan.map(({ ke, label, Ikon }) => (
          <Link
            key={ke}
            to={ke}
            className="flex flex-col items-center gap-2 py-4 px-2 bg-surface border border-line rounded-md hover:border-ink transition-colors"
          >
            <Ikon size={18} strokeWidth={1.75} className="text-ink" />
            <span className="text-xs text-ink font-medium">{label}</span>
          </Link>
        ))}
      </div>
    </section>
  )
}

function ringkas(n: number): string {
  if (!Number.isFinite(n)) return '—'
  if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(1).replace('.0', '') + ' M'
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace('.0', '') + ' jt'
  if (n >= 1_000) return String(Math.round(n / 1_000)) + ' rb'
  return String(Math.round(n))
}

function pctDari(sebagian: number, total: number): number {
  return total > 0 ? Math.round((sebagian / total) * 100) : 0
}
