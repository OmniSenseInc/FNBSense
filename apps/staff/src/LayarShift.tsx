import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import {
  ambilShiftBerjalan,
  bukaShift,
  SESI_HABIS,
  tutupShift,
  type LaporanShift,
  type Shift,
} from './api'
import { rupiah, tanggalJam } from './format'

/**
 * Buka & tutup shift kasir — serah-terima laci.
 *
 * Keadaan shift datang dari SERVER tiap layar ini dibuka, tak pernah dari
 * localStorage. Yang membuka shift pagi dan yang menutupnya sore sering bukan
 * orang (dan bukan tablet) yang sama; id yang tersimpan di satu perangkat
 * berarti shift yang tak bisa ditutup dari perangkat lain.
 *
 * Tidak dipolling, sama seperti /stok: yang berubah di sini cuma saat ada yang
 * menekan tombol, dan angka penjualan berjalan cukup diperbarui lewat tombol
 * muat ulang.
 */
export default function LayarShift({ onKeluar }: { onKeluar: () => void }) {
  /** undefined = belum dimuat, null = tak ada shift terbuka. */
  const [shift, setShift] = useState<Shift | null | undefined>(undefined)
  /** Shift yang BARU SAJA ditutup — dipegang untuk menampilkan Z-report-nya. */
  const [baruDitutup, setBaruDitutup] = useState<Shift | null>(null)
  const [modalAwal, setModalAwal] = useState('')
  const [kasDihitung, setKasDihitung] = useState('')
  const [galat, setGalat] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  const [versi, setVersi] = useState(0)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    setSibuk(true)

    ambilShiftBerjalan()
      .then((hasil) => {
        if (batal) return
        setShift(hasil)
        setGalat(null)
      })
      .catch((err: unknown) => {
        if (batal) return
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal memuat shift.')
      })
      .finally(() => {
        if (!batal) setSibuk(false)
      })

    return () => {
      batal = true
    }
  }, [versi])

  /**
   * Teks isian -> rupiah, atau null kalau tak masuk akal.
   *
   * HANYA digit. Bukan kerewelan format: orang Indonesia menulis dua ratus ribu
   * sebagai "200.000", dan `Number('200.000')` adalah **200** — angka yang lolos
   * `Number.isInteger`, lolos `integer|min:0` di server, dan tersimpan sebagai
   * modal awal seribu kali lebih kecil tanpa satu pun pesan galat. Selisih kas
   * sore harinya lalu menuduh kasir menggelapkan Rp 199.800.
   *
   * Konsekuensinya kasir tak boleh mengetik pemisah ribuan sama sekali — itu
   * disengaja: ditolak di depan mata lebih baik daripada diterima salah.
   */
  const keRupiah = (teks: string): number | null => {
    const bersih = teks.trim()
    if (!/^\d+$/.test(bersih)) return null
    const angka = Number(bersih)

    // Di atas 2^53 rupiah angkanya berhenti bisa dipercaya; tak ada laci yang
    // sebesar itu, tapi yang dikirim ke server tetap harus angka sungguhan.
    return Number.isSafeInteger(angka) ? angka : null
  }

  const jalankan = async (kerja: () => Promise<void>) => {
    setSibuk(true)
    setGalat(null)
    try {
      await kerja()
    } catch (err: unknown) {
      if (err instanceof Error && err.message === SESI_HABIS) {
        keluarRef.current()
        return
      }
      setGalat(err instanceof Error ? err.message : 'Gagal. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  const submitBuka = (e: React.FormEvent) => {
    e.preventDefault()
    const jumlah = keRupiah(modalAwal)
    if (jumlah === null) {
      setGalat('Modal awal harus angka saja, tanpa titik atau koma. Contoh:200000')
      return
    }

    void jalankan(async () => {
      setShift(await bukaShift(jumlah))
      setModalAwal('')
      setBaruDitutup(null)
    })
  }

  const submitTutup = (e: React.FormEvent) => {
    e.preventDefault()
    if (!shift) return
    const jumlah = keRupiah(kasDihitung)
    if (jumlah === null) {
      setGalat('Kas yang dihitung harus angka saja, tanpa titik atau koma. Contoh:350000')
      return
    }

    void jalankan(async () => {
      // Hasil tutup dipindah ke `baruDitutup`, bukan tetap di `shift`: sesudah
      // ini outlet TAK punya shift berjalan lagi, dan membiarkannya di tempat
      // yang sama berarti tombol "Tutup shift" muncul untuk shift yang sudah
      // tertutup.
      setBaruDitutup(await tutupShift(shift.id, jumlah))
      setShift(null)
      setKasDihitung('')
    })
  }

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Shift</h1>
          <p className="text-sm text-slate-600">
            {shift === undefined
              ? 'Memuat…'
              : shift === null
                ? 'Tidak ada shift berjalan'
                : `Dibuka ${tanggalJam(shift.dibukaPada)}`}
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

        {baruDitutup && (
          <section className="mb-4 rounded-md border border-slate-300 bg-white p-4">
            <h2 className="text-sm font-semibold">Shift ditutup</h2>
            <p className="mt-1 text-xs text-slate-600">
              {tanggalJam(baruDitutup.dibukaPada)} — {tanggalJam(baruDitutup.ditutupPada)}
            </p>
            <Laporan laporan={baruDitutup.laporan} />
          </section>
        )}

        {shift === null && (
          <form onSubmit={submitBuka} className="rounded-md border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-semibold">Buka shift</h2>
            <label htmlFor="modal-awal" className="mt-3 block text-sm">
              Modal awal di laci
            </label>
            <input
              id="modal-awal"
              type="number"
              inputMode="numeric"
              min={0}
              step={1}
              value={modalAwal}
              onChange={(e) => setModalAwal(e.target.value)}
              placeholder="200000"
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base tabular-nums"
            />
            <button
              type="submit"
              disabled={sibuk}
              className="mt-3 w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              Buka shift
            </button>
          </form>
        )}

        {shift && (
          <>
            <section className="rounded-md border border-slate-200 bg-white p-4">
              <h2 className="text-sm font-semibold">Penjualan shift berjalan</h2>
              <Laporan laporan={shift.laporan} />
            </section>

            <form
              onSubmit={submitTutup}
              className="mt-4 rounded-md border border-slate-200 bg-white p-4"
            >
              <h2 className="text-sm font-semibold">Tutup shift</h2>
              <label htmlFor="kas-dihitung" className="mt-3 block text-sm">
                Uang tunai yang dihitung di laci
              </label>
              <input
                id="kas-dihitung"
                type="number"
                inputMode="numeric"
                min={0}
                step={1}
                value={kasDihitung}
                onChange={(e) => setKasDihitung(e.target.value)}
                placeholder="350000"
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base tabular-nums"
              />
              {/* Hitung dulu, baru buka amplop: selisih yang ditampilkan sebelum
                  kasir mengetik angkanya sendiri mengundang orang menyalin
                  "kas seharusnya" bulat-bulat, dan selisih yang selalu nol tak
                  pernah menemukan apa pun. */}
              <p className="mt-2 text-xs text-slate-600">
                Hitung isi laci dulu, jangan menyalin angka di atas. Selisihnya muncul setelah
                shift ditutup.
              </p>
              <button
                type="submit"
                disabled={sibuk}
                className="mt-3 w-full rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
              >
                Tutup shift
              </button>
            </form>
          </>
        )}
      </main>
    </div>
  )
}

/** Baris-baris laporan X/Z. Dipakai dua kali: shift berjalan dan shift tertutup. */
function Laporan({ laporan }: { laporan: LaporanShift | null }) {
  if (laporan === null) {
    return <p className="mt-2 text-sm text-slate-600">Laporan belum tersedia.</p>
  }

  return (
    <dl className="mt-3 space-y-1 text-sm">
      <Baris label="Total penjualan" nilai={rupiah(laporan.totalPenjualan)} />
      <Baris
        label="Transaksi"
        nilai={Number.isNaN(laporan.transaksi) ? '—' : String(laporan.transaksi)}
      />
      <Baris label="Tunai" nilai={rupiah(laporan.tunai)} />
      {/* QRIS ditampilkan tapi TIDAK ikut ke kas seharusnya — uangnya tak pernah
          masuk laci. Disebut supaya kasir tak mengira penjualannya hilang. */}
      <Baris label="QRIS (tidak masuk laci)" nilai={rupiah(laporan.qris)} />
      <Baris label="Modal awal" nilai={rupiah(laporan.modalAwal)} />
      <Baris label="Kas seharusnya" nilai={rupiah(laporan.kasSeharusnya)} tebal />
      {laporan.kasDihitung !== null && (
        <Baris label="Kas dihitung" nilai={rupiah(laporan.kasDihitung)} />
      )}
      {laporan.selisih !== null && (
        <div
          className={`flex justify-between gap-3 rounded-md px-2 py-1 font-semibold ${
            laporan.selisih === 0
              ? 'bg-slate-100'
              : laporan.selisih < 0
                ? 'bg-red-50 text-red-800'
                : 'bg-amber-50 text-amber-900'
          }`}
        >
          <dt>Selisih</dt>
          {/* Tanda + ditulis sendiri: rupiah() tak membedakan lebih dan pas,
              padahal "kas lebih" dan "kas kurang" dua kejadian yang berbeda. */}
          <dd className="tabular-nums">
            {laporan.selisih > 0 ? '+' : ''}
            {rupiah(laporan.selisih)}
          </dd>
        </div>
      )}
    </dl>
  )
}

function Baris({ label, nilai, tebal }: { label: string; nilai: string; tebal?: boolean }) {
  return (
    <div className={`flex justify-between gap-3 ${tebal ? 'font-semibold' : ''}`}>
      <dt className="text-slate-600">{label}</dt>
      <dd className="tabular-nums">{nilai}</dd>
    </div>
  )
}
