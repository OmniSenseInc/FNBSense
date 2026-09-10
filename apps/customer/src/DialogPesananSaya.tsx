import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ambilPesanan, type Pesanan } from './api'
import { rupiah } from './format'
import {
  buangPesananSelesai,
  kosongkanPesananSaya,
  kunciPesanan,
  type EntriPesanan,
} from './pesananSaya'
import { TAMPILAN, jelaskanStatus, pesananMasihAktif } from './status'

/**
 * Popup "Pesanan saya" — apa yang SEDANG BERJALAN dari HP ini di meja ini.
 *
 * Alasannya sederhana: pelanggan yang sudah membayar lalu kembali ke menu untuk
 * menambah minum tak lagi punya jalan melihat pesanan sebelumnya, dan nomor
 * pesanannya cuma lewat sekali di layar status.
 *
 * Yang ditampilkan HANYA pesanan yang masih berjalan (menunggu bayar / sedang
 * dibuat). Pesanan yang sudah siap diantar, dibatalkan, atau kedaluwarsa
 * dianggap selesai dan tak ditampilkan — kalau ikut, pesanan pelanggan yang
 * sudah pergi akan terbawa ke pelanggan berikutnya yang duduk di meja yang sama.
 *
 * Diambil saat DIBUKA, bukan di-polling terus: ini tempat yang didatangi, bukan
 * pekerjaan yang datang sendiri. Memuat ulang tiap lima detik di latar halaman
 * menu cuma menghabiskan baterai orang yang sedang memilih kopi.
 */
export default function DialogPesananSaya({
  entri,
  qrToken,
  terbuka,
  onTutup,
  onUbah,
}: {
  entri: EntriPesanan[]
  qrToken: string
  terbuka: boolean
  onTutup: () => void
  /** Sinkronkan daftar entri ke induk setelah dibersihkan (buang yang selesai / tombol Selesai). */
  onUbah: (entri: EntriPesanan[]) => void
}) {
  const ref = useRef<HTMLDialogElement>(null)
  const [daftar, setDaftar] = useState<Pesanan[]>([])
  const [memuat, setMemuat] = useState(false)
  const [galat, setGalat] = useState(false)
  const [konfirmasiSelesai, setKonfirmasiSelesai] = useState(false)

  // Entri terbaru selalu tersedia lewat ref. Effect pemuatan di bawah hanya
  // jalan saat popup DIBUKA — bukan tiap `entri` berubah — supaya pembersihan
  // otomatis tak memicu muat-ulang yang bikin layar berkedip "Memuat…".
  const entriRef = useRef(entri)
  entriRef.current = entri

  // showModal(), bukan atribut `open`: tanpa itu tak ada backdrop, tak ada
  // kunci fokus, dan tombol back HP tak menutup apa pun. Pelajaran yang sama
  // sudah dipakai di lembar detail menu.
  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return

    if (terbuka && !dialog.open) dialog.showModal()
    if (!terbuka && dialog.open) dialog.close()
  }, [terbuka])

  useEffect(() => {
    if (!terbuka) return

    let batal = false
    const daftarEntri = entriRef.current
    setMemuat(true)
    setGalat(false)
    setKonfirmasiSelesai(false)

    // allSettled, bukan all: satu pesanan yang gagal diambil (sudah dihapus,
    // jaringan putus di tengah) tak boleh mengosongkan seluruh daftar. Yang
    // gagal cukup tak muncul.
    Promise.allSettled(daftarEntri.map((e) => ambilPesanan(e.id))).then((hasil) => {
      if (batal) return

      const berhasil = hasil
        .filter((h): h is PromiseFulfilledResult<Pesanan> => h.status === 'fulfilled')
        .map((h) => h.value)

      const aktif = berhasil.filter((p) => pesananMasihAktif(p.status, p.readyAt))
      const idSelesai = berhasil
        .filter((p) => !pesananMasihAktif(p.status, p.readyAt))
        .map((p) => p.id)

      // Buang yang sudah selesai dari catatan HP — bukan cuma dari layar —
      // supaya hitungan tombol "Pesanan saya" di menu ikut benar di kunjungan
      // berikutnya, dan tak menumpuk pesanan yang sudah lewat.
      if (idSelesai.length > 0) {
        onUbah(buangPesananSelesai(kunciPesanan(qrToken), idSelesai))
      }

      setDaftar(aktif)
      // Galat hanya kalau NOL yang berhasil — sebagian berhasil masih berguna.
      setGalat(berhasil.length === 0 && daftarEntri.length > 0)
      setMemuat(false)
    })

    return () => {
      batal = true
    }
  }, [terbuka, qrToken, onUbah])

  const selesaikan = () => {
    kosongkanPesananSaya(kunciPesanan(qrToken))
    onUbah([])
    setKonfirmasiSelesai(false)
    onTutup()
  }

  return (
    <dialog
      ref={ref}
      // onClose menangkap Esc dan tombol back HP — dua jalan tutup yang tak
      // lewat tombol kita, dan tanpa ini state induk yakin dialognya masih
      // terbuka sehingga tombolnya tak bisa dipakai lagi.
      onClose={onTutup}
      className="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-lg p-0 backdrop:bg-black/40"
    >
      <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 className="text-base font-semibold">Pesanan saya</h2>
        <button
          type="button"
          onClick={onTutup}
          aria-label="Tutup"
          className="h-9 w-9 rounded-md text-xl leading-none text-slate-500"
        >
          ✕
        </button>
      </div>

      <div className="max-h-[70svh] overflow-y-auto p-4">
        {memuat && <p className="text-sm text-slate-600">Memuat pesanan…</p>}

        {!memuat && galat && (
          <p className="text-sm text-slate-600">
            Gagal memuat pesanan. Tutup lalu buka lagi, atau tanya kasir.
          </p>
        )}

        {!memuat && !galat && daftar.length === 0 && (
          <p className="text-sm text-slate-600">Tidak ada pesanan berjalan dari HP ini hari ini.</p>
        )}

        <ul className="flex flex-col gap-3">
          {daftar.map((pesanan) => {
            // adaQris=false: di dalam popup tak ada QR yang terpampang, jadi
            // kalimat "pindai QR berikut" akan menunjuk sesuatu yang tak ada.
            const { judul, rasa } = jelaskanStatus(
              pesanan.status,
              false,
              pesanan.readyAt !== null,
            )
            const tampilan = TAMPILAN[rasa]

            return (
              <li key={pesanan.id} className={`rounded-md border p-3 ${tampilan.kotak}`}>
                <div className="flex items-center gap-2">
                  <span
                    aria-hidden="true"
                    className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-sm leading-none ${tampilan.lingkaran}`}
                  >
                    {tampilan.ikon}
                  </span>
                  <p className="text-sm font-semibold">{judul}</p>
                  <p className="ml-auto text-sm tabular-nums opacity-70">
                    {pesanan.order_number}
                  </p>
                </div>

                <ul className="mt-2 flex flex-col gap-1">
                  {pesanan.items.map((item, i) => (
                    // Indeks ikut ke key: satu pesanan boleh memuat produk yang
                    // sama dua kali dengan catatan berbeda, jadi product_id
                    // saja tak menjamin ketunggalan.
                    <li
                      key={`${item.product_id}-${i}`}
                      className="flex justify-between gap-2 text-sm"
                    >
                      <span className="min-w-0">
                        {item.qty}× {item.product_name}
                        {item.note && (
                          <span className="block truncate text-amber-800">
                            Catatan: {item.note}
                          </span>
                        )}
                      </span>
                      <span className="shrink-0 tabular-nums">{rupiah(item.line_total)}</span>
                    </li>
                  ))}
                </ul>

                <div className="mt-2 flex items-center justify-between border-t border-slate-200 pt-2">
                  <p className="text-sm font-semibold">Total</p>
                  <p className="text-sm font-semibold tabular-nums">
                    {rupiah(pesanan.grand_total)}
                  </p>
                </div>

                {/* Jalan ke layar status penuh — di situlah QRIS dan tombol
                    "Saya sudah bayar" berada. Popup ini sengaja tak memuat
                    dua-duanya: menyalin tombol jalur uang ke tempat kedua
                    berarti dua tempat yang harus sama-sama benar. */}
                <Link
                  to={`/t/${qrToken}/order/${pesanan.id}`}
                  className="mt-2 block text-sm font-semibold underline"
                >
                  Lihat detail & cara bayar
                </Link>
              </li>
            )
          })}
        </ul>
      </div>

      {/* Tombol "Selesai" — membersihkan catatan supaya pelanggan berikutnya di
          meja yang sama mulai dari nol. Konfirmasi dua-langkah, bukan dialog
          bawaan: tombolnya sendiri berubah jadi "Yakin?", supaya tak ada
          jendela asing yang mengejutkan. */}
      {entri.length > 0 && (
        <div className="border-t border-slate-200 p-3">
          {konfirmasiSelesai ? (
            <div className="flex gap-2">
              <button
                type="button"
                onClick={() => setKonfirmasiSelesai(false)}
                className="h-11 flex-1 rounded-md border border-slate-300 text-sm font-semibold"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={selesaikan}
                className="h-11 flex-1 rounded-md bg-slate-900 text-sm font-semibold text-white"
              >
                Ya, bersihkan
              </button>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => setKonfirmasiSelesai(true)}
              className="h-11 w-full rounded-md border border-slate-300 text-sm font-semibold text-slate-600"
            >
              Selesai makan — bersihkan riwayat
            </button>
          )}
        </div>
      )}
    </dialog>
  )
}
