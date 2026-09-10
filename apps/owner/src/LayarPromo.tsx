import { useEffect, useState } from 'react'
import { Pause, Play, Plus, Tag, Trash2, X } from 'lucide-react'
import {
  aktifkanPromo,
  ambilProduk,
  ambilPromo,
  ambilTemplatePromo,
  buatPromo,
  hapusPromo,
  pausePromo,
  peranSaya,
  SESI_HABIS,
  type PayloadPromo,
  type Produk,
  type Promo,
  type TemplatePromo,
} from './api'
import { rupiah } from './format'

/** Badge status. Kunci dari `effective_status` server (termasuk scheduled/expired). */
const STATUS: Record<string, { label: string; kelas: string }> = {
  draft: { label: 'Draft', kelas: 'bg-stone-100 text-stone-500' },
  active: { label: 'Aktif', kelas: 'bg-sage-100 text-sage-700' },
  paused: { label: 'Pause', kelas: 'bg-stone-100 text-stone-500' },
  scheduled: { label: 'Terjadwal', kelas: 'bg-stone-100 text-stone-500' },
  expired: { label: 'Berakhir', kelas: 'bg-stone-100 text-stone-400' },
}

/** Ringkasan diskon satu baris, sesuai template. */
function ringkasDiskon(p: Promo): string {
  switch (p.template) {
    case 'order_percentage':
      return `Diskon ${p.persen ?? 0}%`
    case 'order_fixed':
      return `Potongan ${rupiah(p.nominal ?? 0)}`
    case 'product_percentage':
      return `Diskon ${p.persen ?? 0}% produk terpilih`
    case 'bundle_fixed_price':
      return `Paket ${rupiah(p.nominal ?? 0)}`
    default:
      return p.template
  }
}

export default function LayarPromo({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Promo[] | null>(null)
  const [template, setTemplate] = useState<TemplatePromo[]>([])
  const [produk, setProduk] = useState<Produk[]>([])
  const [galat, setGalat] = useState('')
  const owner = peranSaya() === 'owner'

  const [bukaForm, setBukaForm] = useState(false)
  const [nama, setNama] = useState('')
  const [kunciTemplate, setKunciTemplate] = useState('')
  const [persen, setPersen] = useState('')
  const [nominal, setNominal] = useState('')
  const [minSubtotal, setMinSubtotal] = useState('')
  const [terpilih, setTerpilih] = useState<{ id: string; qty: number }[]>([])
  const [menyimpan, setMenyimpan] = useState(false)
  const [sedangAksi, setSedangAksi] = useState<string | null>(null)

  useEffect(() => {
    let batal = false
    Promise.all([ambilPromo(), ambilTemplatePromo(), ambilProduk()])
      .then(([d, t, p]) => {
        if (batal) return
        setDaftar(d)
        setTemplate(t)
        setProduk(p)
        if (t.length > 0 && kunciTemplate === '') setKunciTemplate(t[0].key)
      })
      .catch(e => {
        if (batal) return
        if (e instanceof Error && e.message === SESI_HABIS) onKeluar()
        else setGalat(e instanceof Error ? e.message : 'Gagal memuat.')
      })
    return () => {
      batal = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onKeluar])

  const labelTemplate = (k: string) => template.find(t => t.key === k)?.label ?? k

  const butuhProduk = kunciTemplate === 'product_percentage' || kunciTemplate === 'bundle_fixed_price'
  const butuhPersen = kunciTemplate === 'order_percentage' || kunciTemplate === 'product_percentage'

  const persenAngka = Number(persen) || 0
  const nominalAngka = Number(nominal) || 0
  const minSubtotalAngka = Number(minSubtotal) || 0

  const minimalProduk = kunciTemplate === 'bundle_fixed_price' ? 2 : 1
  const formValid =
    nama.trim() !== '' &&
    kunciTemplate !== '' &&
    (butuhPersen ? persenAngka >= 1 && persenAngka <= 100 : true) &&
    (!butuhPersen ? nominalAngka >= 1 : true) &&
    (!butuhProduk || terpilih.length >= minimalProduk)

  const toggleProduk = (id: string) => {
    setTerpilih(prev =>
      prev.some(p => p.id === id) ? prev.filter(p => p.id !== id) : [...prev, { id, qty: 1 }],
    )
  }

  const ubahQty = (id: string, qty: number) => {
    setTerpilih(prev => prev.map(p => (p.id === id ? { ...p, qty: Math.max(1, qty) } : p)))
  }

  const simpan = async () => {
    if (menyimpan) return
    if (!formValid) {
      // Diam-diam pulang saat form belum lengkap = kasir mengklik-klik tanpa
      // tahu salahnya apa (dan input tidak required → tak ada bubble browser).
      setGalat('Isi nama dan nilai promo dulu.')
      return
    }
    setMenyimpan(true)
    setGalat('')
    try {
      const payload: PayloadPromo = { name: nama.trim(), template: kunciTemplate }
      if (butuhPersen) payload.percentage = persenAngka
      else payload.amount = nominalAngka
      if (minSubtotalAngka > 0) payload.min_subtotal = minSubtotalAngka
      if (butuhProduk) {
        payload.products = terpilih.map(p => ({ product_id: p.id, required_qty: p.qty }))
      }
      await buatPromo(payload)
      setNama('')
      setPersen('')
      setNominal('')
      setMinSubtotal('')
      setTerpilih([])
      setBukaForm(false)
      setDaftar(await ambilPromo())
    } catch (e) {
      setGalat(e instanceof Error ? e.message : 'Gagal menyimpan.')
    } finally {
      setMenyimpan(false)
    }
  }

  const aksi = async (id: string, fn: () => Promise<void>) => {
    if (sedangAksi) return
    setSedangAksi(id)
    setGalat('')
    try {
      await fn()
      setDaftar(await ambilPromo())
    } catch (e) {
      setGalat(e instanceof Error ? e.message : 'Gagal.')
    } finally {
      setSedangAksi(null)
    }
  }

  return (
    <div className="max-w-3xl mx-auto px-4 py-5 space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-bold text-stone-900">Promo</h1>
        {owner && (
          <button
            onClick={() => setBukaForm(v => !v)}
            className="flex items-center gap-1.5 text-sm font-semibold text-sage-700 bg-sage-50 hover:bg-sage-100 px-3 py-2 rounded-xl"
          >
            {bukaForm ? <X size={16} /> : <Plus size={16} />}
            {bukaForm ? 'Tutup' : 'Tambah promo'}
          </button>
        )}
      </div>

      {galat && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">{galat}</div>
      )}

      {/* — Form buat promo (owner saja) — */}
      {owner && bukaForm && (
        <section className="bg-white rounded-2xl border border-stone-200 p-4 space-y-3">
          <div>
            <label className="text-xs text-stone-400 font-medium">Jenis promo</label>
            <select
              value={kunciTemplate}
              onChange={e => setKunciTemplate(e.target.value)}
              className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm bg-white"
            >
              {template.map(t => (
                <option key={t.key} value={t.key}>
                  {t.label}
                </option>
              ))}
            </select>
            {kunciTemplate && (
              <p className="mt-1 text-[11px] text-stone-400">
                {template.find(t => t.key === kunciTemplate)?.description}
              </p>
            )}
          </div>

          <div>
            <label className="text-xs text-stone-400 font-medium">Nama promo</label>
            <input
              value={nama}
              onChange={e => setNama(e.target.value)}
              placeholder="mis. Diskon akhir pekan"
              className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm"
            />
          </div>

          {butuhPersen ? (
            <div>
              <label className="text-xs text-stone-400 font-medium">Persentase (%)</label>
              <input
                inputMode="numeric"
                value={persen}
                onChange={e => setPersen(e.target.value.replace(/[^0-9]/g, ''))}
                placeholder="0"
                className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm"
              />
            </div>
          ) : (
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
          )}

          <div>
            <label className="text-xs text-stone-400 font-medium">Minimal belanja (Rp, opsional)</label>
            <input
              inputMode="numeric"
              value={minSubtotal}
              onChange={e => setMinSubtotal(e.target.value.replace(/[^0-9]/g, ''))}
              placeholder="0"
              className="mt-1 w-full rounded-xl border border-stone-200 px-3 py-2.5 text-sm"
            />
          </div>

          {butuhProduk && (
            <div>
              <label className="text-xs text-stone-400 font-medium">
                Pilih produk{kunciTemplate === 'bundle_fixed_price' ? ' (minimal 2)' : ''}
              </label>
              <div className="mt-1 space-y-1 max-h-56 overflow-y-auto border border-stone-200 rounded-xl p-2">
                {produk.length === 0 && <p className="text-sm text-stone-400 p-2">Belum ada produk.</p>}
                {produk.map(p => {
                  const dipilih = terpilih.some(t => t.id === p.id)
                  return (
                    <div key={p.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg">
                      <input
                        type="checkbox"
                        checked={dipilih}
                        onChange={() => toggleProduk(p.id)}
                        className="h-4 w-4"
                      />
                      <span className="flex-1 text-sm text-stone-700 truncate">{p.nama}</span>
                      {dipilih && kunciTemplate === 'bundle_fixed_price' && (
                        <input
                          inputMode="numeric"
                          value={terpilih.find(t => t.id === p.id)?.qty ?? 1}
                          onChange={e => ubahQty(p.id, Number(e.target.value.replace(/[^0-9]/g, '')) || 1)}
                          className="w-12 rounded-lg border border-stone-200 px-2 py-1 text-sm text-center"
                          aria-label="Jumlah"
                        />
                      )}
                    </div>
                  )
                })}
              </div>
            </div>
          )}

          <button
            onClick={simpan}
            disabled={!formValid || menyimpan}
            className="w-full py-2.5 rounded-xl bg-sage-600 text-white text-sm font-semibold disabled:opacity-40"
          >
            {menyimpan ? 'Menyimpan…' : 'Buat promo'}
          </button>
        </section>
      )}

      {/* — Daftar promo — */}
      {daftar ? (
        daftar.length > 0 ? (
          <section className="bg-white rounded-2xl border border-stone-200 divide-y divide-stone-100">
            {daftar.map(p => {
              const s = STATUS[p.statusEfektif] ?? STATUS.draft
              return (
                <div key={p.id} className="flex items-center gap-3 px-4 py-3">
                  <span className="w-9 h-9 rounded-xl bg-sage-50 text-sage-600 flex items-center justify-center shrink-0">
                    <Tag size={16} strokeWidth={1.75} />
                  </span>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-stone-700 truncate">{p.nama}</p>
                    <p className="text-[11px] text-stone-400">
                      {labelTemplate(p.template)} · {ringkasDiskon(p)}
                      {p.minSubtotal > 0 ? ` · min ${rupiah(p.minSubtotal)}` : ''}
                    </p>
                  </div>
                  <span className={`text-[11px] font-semibold px-2 py-1 rounded-full shrink-0 ${s.kelas}`}>
                    {s.label}
                  </span>
                  {owner && (
                    <div className="flex items-center gap-1 shrink-0">
                      {p.status === 'active' ? (
                        <button
                          onClick={() => aksi(p.id, () => pausePromo(p.id))}
                          disabled={sedangAksi !== null}
                          aria-label="Pause promo"
                          className="p-1.5 rounded-lg text-stone-400 hover:bg-stone-100 hover:text-stone-700"
                        >
                          <Pause size={16} />
                        </button>
                      ) : (
                        <button
                          onClick={() => aksi(p.id, () => aktifkanPromo(p.id))}
                          disabled={sedangAksi !== null}
                          aria-label="Aktifkan promo"
                          className="p-1.5 rounded-lg text-stone-400 hover:bg-sage-50 hover:text-sage-700"
                        >
                          <Play size={16} />
                        </button>
                      )}
                      {p.status !== 'active' && (
                        <button
                          onClick={() => aksi(p.id, () => hapusPromo(p.id))}
                          disabled={sedangAksi !== null}
                          aria-label="Hapus promo"
                          className="p-1.5 rounded-lg text-stone-400 hover:bg-red-50 hover:text-red-600"
                        >
                          <Trash2 size={16} />
                        </button>
                      )}
                    </div>
                  )}
                </div>
              )
            })}
          </section>
        ) : (
          <p className="text-sm text-stone-400 text-center py-8">
            Belum ada promo.{owner ? ' Bikin yang pertama.' : ''}
          </p>
        )
      ) : (
        !galat && <div className="py-16 text-center text-sm text-stone-400">Memuat…</div>
      )}
    </div>
  )
}
