import { useEffect, useMemo, useState } from 'react'
import { Ban, Clock } from 'lucide-react'
import {
  ambilBatal,
  ambilKedaluwarsa,
  ambilStaf,
  SESI_HABIS,
  tanggalJamWIB,
  type Pesanan,
  type Staf,
} from './api'
import { rupiah } from './format'
import { kelompokkanPerPembatal, urutkanTerbaru } from './pembatalan'

type Tab = 'batal' | 'kedaluwarsa'

/**
 * Layar "Pembatalan" — detektif void + pantau kedaluwarsa untuk owner.
 *
 * Dua muka pesanan yang gagal jadi pendapatan, dipisah karena beda sifat:
 *
 *   1. Dibatalkan — ulah kasir (fraud paling umum: terima tunai, batalkan
 *      pesanan, uang masuk kantong sendiri). Server sudah mencatat
 *      `cancelled_by` + alasan; layar ini menyajikannya per kasir.
 *   2. Kedaluwarsa — bukan ulah siapa pun: pelanggan tak kunjung bayar sampai
 *      lewat expires_at, lalu scheduler menandainya expired. Tak ada pembatal,
 *      jadi cukup ringkasan + rincian, tanpa pengelompokan per kasir.
 *
 * Uang adalah urusan server — layar ini tidak menghitung diskon/pajak sendiri,
 * hanya menjumlah nominal yang sudah dikirim (dan menolak NaN, bukan menampilkan
 * angka bohong).
 */
export default function LayarBatal({ onKeluar }: { onKeluar: () => void }) {
  const [daftarBatal, setDaftarBatal] = useState<Pesanan[] | null>(null)
  const [daftarKedaluwarsa, setDaftarKedaluwarsa] = useState<Pesanan[] | null>(null)
  const [staf, setStaf] = useState<Staf[]>([])
  const [galat, setGalat] = useState('')
  const [tab, setTab] = useState<Tab>('batal')

  useEffect(() => {
    let batal = false

    const tanganiGalat = (e: unknown) => {
      if (batal) return
      if (e instanceof Error && e.message === SESI_HABIS) onKeluar()
      else setGalat(e instanceof Error ? e.message : 'Gagal memuat.')
    }

    // Dua sumber utama dipisah, bukan Promise.all: satu gagal tak boleh
    // menenggelamkan tab yang lain. Nama staf cuma pelengkap (best-effort):
    // kalau gagal (mis. akun manager memaksa buka layar ini, sementara daftar
    // staf owner-only), nama tampil "Kasir (dihapus)" tapi daftar TETAP muncul.
    ambilBatal()
      .then((d) => {
        if (!batal) setDaftarBatal(d)
      })
      .catch(tanganiGalat)

    ambilKedaluwarsa()
      .then((d) => {
        if (!batal) setDaftarKedaluwarsa(d)
      })
      .catch(tanganiGalat)

    ambilStaf()
      .then((s) => {
        if (!batal) setStaf(s)
      })
      .catch(() => {})

    return () => {
      batal = true
    }
  }, [onKeluar])

  // id kasir (IAM) -> nama, buat menerjemahkan `pembatal` jadi nama manusia.
  const namaStaf = useMemo(() => new Map(staf.map((s) => [s.id, s.nama])), [staf])

  // Terbaru di atas. Server mengirim urut created_at, bukan jam mutasi terakhir
  // — jadi diurutkan ulang memakai urutkanTerbaru (logika murni, teruji terpisah).
  const urutBatal = useMemo(() => urutkanTerbaru(daftarBatal ?? []), [daftarBatal])
  const urutKedaluwarsa = useMemo(() => urutkanTerbaru(daftarKedaluwarsa ?? []), [daftarKedaluwarsa])

  const totalBatal = useMemo(
    () => urutBatal.reduce((t, p) => t + (Number.isFinite(p.grand_total) ? p.grand_total : 0), 0),
    [urutBatal],
  )
  const totalKedaluwarsa = useMemo(
    () =>
      urutKedaluwarsa.reduce((t, p) => t + (Number.isFinite(p.grand_total) ? p.grand_total : 0), 0),
    [urutKedaluwarsa],
  )

  // Inti "detektif"-nya: siapa yang paling sering membatalkan. Logika murni di
  // pembatalan.ts, teruji terpisah. Hanya berlaku untuk tab "Dibatalkan" —
  // kedaluwarsa tak punya pembatal.
  const perKasir = useMemo(() => kelompokkanPerPembatal(urutBatal, namaStaf), [urutBatal, namaStaf])

  const aktif = tab === 'batal' ? daftarBatal : daftarKedaluwarsa

  return (
    <div className="max-w-3xl mx-auto px-4 py-5 space-y-4">
      <div>
        <h1 className="text-lg font-bold text-stone-900">Pembatalan</h1>
        <p className="text-sm text-stone-500 mt-0.5">
          Pantau pesanan yang gagal jadi pendapatan — dibatalkan kasir atau kedaluwarsa.
        </p>
      </div>

      {/* — Pilihan muka — */}
      <div className="flex rounded-xl bg-stone-100 p-1 gap-1">
        <button
          type="button"
          onClick={() => setTab('batal')}
          aria-pressed={tab === 'batal'}
          className={
            'flex-1 rounded-lg px-4 py-2 text-sm font-medium transition ' +
            (tab === 'batal'
              ? 'bg-white text-stone-900 shadow-sm'
              : 'text-stone-500 hover:text-stone-700')
          }
        >
          Dibatalkan
        </button>
        <button
          type="button"
          onClick={() => setTab('kedaluwarsa')}
          aria-pressed={tab === 'kedaluwarsa'}
          className={
            'flex-1 rounded-lg px-4 py-2 text-sm font-medium transition ' +
            (tab === 'kedaluwarsa'
              ? 'bg-white text-stone-900 shadow-sm'
              : 'text-stone-500 hover:text-stone-700')
          }
        >
          Kedaluwarsa
        </button>
      </div>

      {galat && (
        <div className="bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">
          {galat}
        </div>
      )}

      {aktif === null ? (
        !galat && <div className="py-16 text-center text-sm text-stone-400">Memuat…</div>
      ) : tab === 'batal' ? (
        urutBatal.length === 0 ? (
          <p className="text-sm text-stone-400 text-center py-16">Belum ada pembatalan. Bagus.</p>
        ) : (
          <>
            {/* — Ringkasan — */}
            <section className="bg-white rounded-2xl border border-stone-200 p-4 flex items-center justify-between">
              <div>
                <p className="text-xs text-stone-400 font-medium">Total pembatalan</p>
                <p className="text-2xl font-bold text-stone-900 tabular-nums">{urutBatal.length}</p>
              </div>
              <div className="text-right">
                <p className="text-xs text-stone-400 font-medium">Nominal dibatalkan</p>
                <p className="text-2xl font-bold text-red-600 tabular-nums">{rupiah(totalBatal)}</p>
              </div>
            </section>

            {/* — Per kasir — */}
            {perKasir.length > 0 && (
              <section className="bg-white rounded-2xl border border-stone-200">
                <h2 className="text-sm font-semibold text-stone-800 px-4 pt-4">Per kasir</h2>
                <div className="divide-y divide-stone-100 mt-1">
                  {/* Kunci pakai id pembatal, BUKAN nama: dua kasir yang sudah
                      dihapus sama-sama tampil "Kasir (dihapus)", dan nama kembar
                      membuat React mengira keduanya satu elemen. */}
                  {perKasir.map((k) => (
                    <div key={k.id} className="flex items-center gap-3 px-4 py-3">
                      <div className="flex-1 min-w-0">
                        <p className="text-sm text-stone-700 truncate">{k.nama}</p>
                        <p className="text-[11px] text-stone-400 tabular-nums">
                          {k.jumlah} pembatalan
                        </p>
                      </div>
                      <span className="text-sm font-semibold text-stone-800 tabular-nums shrink-0">
                        {rupiah(k.nominal)}
                      </span>
                    </div>
                  ))}
                </div>
              </section>
            )}

            {/* — Daftar pembatalan — */}
            <section className="bg-white rounded-2xl border border-stone-200">
              <h2 className="text-sm font-semibold text-stone-800 px-4 pt-4">Rincian</h2>
              <div className="divide-y divide-stone-100 mt-1">
                {urutBatal.map((p) => (
                  <div key={p.id} className="flex items-start gap-3 px-4 py-3">
                    <span className="w-9 h-9 rounded-xl bg-red-50 text-red-500 flex items-center justify-center shrink-0">
                      <Ban size={16} strokeWidth={1.75} />
                    </span>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm text-stone-700">
                        <span className="font-semibold tabular-nums">{p.order_number}</span>
                        <span className="text-stone-400"> · {p.customer_name || 'Tanpa nama'}</span>
                      </p>
                      <p className="text-[11px] text-stone-400">
                        {namaStaf.get(p.pembatal ?? '') ?? 'Kasir (dihapus)'} ·{' '}
                        {tanggalJamWIB(p.diubahPada)}
                      </p>
                      {p.alasan && <p className="text-[11px] text-stone-500 mt-0.5">Alasan: {p.alasan}</p>}
                    </div>
                    <span className="text-sm font-semibold text-red-600 tabular-nums shrink-0">
                      {rupiah(p.grand_total)}
                    </span>
                  </div>
                ))}
              </div>
            </section>
          </>
        )
      ) : urutKedaluwarsa.length === 0 ? (
        <p className="text-sm text-stone-400 text-center py-16">Belum ada pesanan kedaluwarsa.</p>
      ) : (
        <>
          {/* — Ringkasan kedaluwarsa — */}
          <section className="bg-white rounded-2xl border border-stone-200 p-4 flex items-center justify-between">
            <div>
              <p className="text-xs text-stone-400 font-medium">Total kedaluwarsa</p>
              <p className="text-2xl font-bold text-stone-900 tabular-nums">
                {urutKedaluwarsa.length}
              </p>
            </div>
            <div className="text-right">
              <p className="text-xs text-stone-400 font-medium">Nominal kedaluwarsa</p>
              <p className="text-2xl font-bold text-stone-900 tabular-nums">
                {rupiah(totalKedaluwarsa)}
              </p>
            </div>
          </section>

          {/* — Daftar kedaluwarsa — */}
          <section className="bg-white rounded-2xl border border-stone-200">
            <h2 className="text-sm font-semibold text-stone-800 px-4 pt-4">Rincian</h2>
            <div className="divide-y divide-stone-100 mt-1">
              {urutKedaluwarsa.map((p) => (
                <div key={p.id} className="flex items-start gap-3 px-4 py-3">
                  <span className="w-9 h-9 rounded-xl bg-stone-100 text-stone-500 flex items-center justify-center shrink-0">
                    <Clock size={16} strokeWidth={1.75} />
                  </span>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm text-stone-700">
                      <span className="font-semibold tabular-nums">{p.order_number}</span>
                      <span className="text-stone-400"> · {p.customer_name || 'Tanpa nama'}</span>
                    </p>
                    <p className="text-[11px] text-stone-400">
                      Kedaluwarsa · {tanggalJamWIB(p.diubahPada)}
                    </p>
                  </div>
                  <span className="text-sm font-semibold text-stone-800 tabular-nums shrink-0">
                    {rupiah(p.grand_total)}
                  </span>
                </div>
              ))}
            </div>
          </section>
        </>
      )}
    </div>
  )
}
