import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { riwayatHariIni } from './antrean'
import { ambilRiwayat, SESI_HABIS, type Pesanan } from './api'
import { jam, labelMeja, rupiah } from './format'

/** Kosakata server -> bahasa yang dibaca kasir. */
const LABEL_BAYAR: Record<string, string> = {
  cash: 'Tunai',
  qris_static: 'QRIS',
}

/**
 * Pesanan yang sudah dibayar hari ini.
 *
 * Ada untuk satu alasan konkret: sebelum ini, nota hidup beberapa detik sesudah
 * konfirmasi lalu lenyap selamanya. Pelanggan yang kembali setengah jam kemudian
 * — struknya ketinggalan, minta dicetak ulang, atau menanyakan apa saja yang dia
 * bayar — tak bisa dilayani sama sekali.
 *
 * SENGAJA tanpa polling, berbeda dari antrean. Antrean adalah pekerjaan yang
 * datang sendiri, jadi ia harus mengetuk. Riwayat adalah tempat yang didatangi
 * untuk mencari satu hal tertentu; memuat ulang tiap lima detik cuma memindahkan
 * baris di bawah jari kasir yang sedang membacanya.
 */
export default function LayarRiwayat({ onKeluar }: { onKeluar: () => void }) {
  const [daftar, setDaftar] = useState<Pesanan[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false

    ambilRiwayat()
      .then((hasil) => {
        if (batal) return
        setDaftar(riwayatHariIni(hasil))
        setGalat(null)
      })
      .catch((err: unknown) => {
        if (batal) return
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal memuat riwayat.')
      })

    return () => {
      batal = true
    }
  }, [])

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <Link to="/" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
          ← Antrean
        </Link>
        <div className="text-right">
          <h1 className="text-base font-semibold">Dibayar hari ini</h1>
          <p className="text-sm text-slate-600">
            {daftar === null ? 'Memuat…' : `${daftar.length} pesanan`}
          </p>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-4 py-4">
        {galat && (
          <p role="alert" className="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {galat}
          </p>
        )}

        {daftar !== null && daftar.length === 0 && (
          <p className="py-16 text-center text-sm text-slate-600">
            Belum ada pesanan yang dibayar hari ini.
          </p>
        )}

        <ul className="flex flex-col gap-2">
          {daftar?.map((pesanan) => {
            const meja = labelMeja(pesanan.meja, pesanan.tipe)

            return (
              <li key={pesanan.id}>
                {/* Seluruh baris jadi tautan, bukan tombol kecil di ujungnya:
                    inilah satu-satunya hal yang dilakukan kasir di layar ini,
                    dan sasaran sentuh sebesar kartu jauh lebih sulit meleset
                    di jam sibuk. */}
                <Link
                  to={`/nota/${pesanan.id}`}
                  className="block rounded-md border border-slate-200 bg-white p-4"
                >
                  <div className="flex items-baseline justify-between gap-3">
                    <p className="text-base font-semibold">
                      <span className="tabular-nums">{pesanan.order_number}</span>
                      {meja && <span className="ml-2 text-slate-600">· {meja}</span>}
                    </p>
                    <p className="text-base font-semibold tabular-nums">
                      {rupiah(pesanan.grand_total)}
                    </p>
                  </div>
                  <p className="mt-1 text-sm text-slate-600">
                    {/* Jam BAYAR, bukan jam pesan: yang dicari kasir adalah
                        orang yang baru saja meninggalkan meja kasir. */}
                    Dibayar {jam(pesanan.waktuBayar)}
                    {pesanan.caraBayar && (
                      <span>
                        {' · '}
                        {LABEL_BAYAR[pesanan.caraBayar] ?? pesanan.caraBayar}
                      </span>
                    )}
                    {' · '}
                    {pesanan.customer_name}
                  </p>
                </Link>
              </li>
            )
          })}
        </ul>
      </main>
    </div>
  )
}
