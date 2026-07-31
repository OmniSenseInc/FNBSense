import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { totalKembar } from './antrean'
import {
  ambilAntrean,
  batalkanPesanan,
  konfirmasiBayar,
  logout,
  SESI_HABIS,
  type CaraBayar,
  type Pesanan,
} from './api'
import { jam, labelMeja, rupiah, sisaMenit } from './format'

/**
 * Jeda polling. 5 detik: kasir baru boleh tahu ada pesanan masuk paling lambat
 * selama itu. Push (`order.created`) belum ada — sengaja, sampai polling
 * benar-benar terasa berat.
 *
 * Ia sekaligus yang menggerakkan hitung mundur di kartu: tiap muat ulang
 * mengganti daftar, komponen dirender ulang, dan sisa waktunya dihitung ulang
 * dari jam sekarang. Jadi nol timer tambahan — setInterval kedua di sini cuma
 * akan berdetak di antara dua polling tanpa membawa kabar baru.
 */
const JEDA_MS = 5000

/**
 * Di bawah ini sisa waktu berhenti jadi keterangan dan mulai jadi peringatan.
 *
 * Lima menit kira-kira sepadan dengan waktu kasir menyelesaikan satu antrean
 * pendek — cukup untuk sempat menengok HP dan menerima pembayaran sebelum
 * `orders:expire` menyapu pesanan yang uangnya mungkin sudah masuk.
 */
const AMBANG_MENDESAK = 5

const CARA_BAYAR: Array<{ nilai: CaraBayar; label: string }> = [
  { nilai: 'qris_static', label: 'QRIS' },
  { nilai: 'cash', label: 'Tunai' },
]

/**
 * Baris mana yang sedang ditanyai, dan sedang ditanyai APA.
 *
 * Dua aksi berbahaya berbagi satu kartu, jadi keduanya harus mustahil terbuka
 * bersamaan — satu nilai, bukan dua boolean yang bisa menyala berbarengan.
 */
type Aksi = { id: string; mode: 'bayar' | 'batal' }

export default function LayarAntrean({ onKeluar }: { onKeluar: () => void }) {
  const navigate = useNavigate()

  const [daftar, setDaftar] = useState<Pesanan[] | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  /** Kartu yang sedang membuka langkah kedua. Null = semua kartu tenang. */
  const [aksi, setAksi] = useState<Aksi | null>(null)
  /** Baris yang permintaan konfirmasinya sedang jalan — kunci anti klik ganda. */
  const [kirimId, setKirimId] = useState<string | null>(null)
  /** Dinaikkan untuk memaksa muat ulang segera, tanpa menunggu jeda 5 detik. */
  const [versi, setVersi] = useState(0)

  // Lewat ref supaya identitas fungsi dari App tak pernah memicu effect
  // menyalakan polling kedua yang berjalan berdampingan.
  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    let batal = false
    let timer: number

    const muat = async () => {
      try {
        const data = await ambilAntrean()
        if (batal) return
        setDaftar(data)
        setGalat(null)
      } catch (err) {
        if (batal) return
        // Sesi benar-benar habis -> pulangkan ke login dan BERHENTI bertanya.
        // Polling yang jalan terus cuma menumpuk 401 tanpa hasil.
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        // Gagal sekali bukan gagal selamanya (Wi-Fi kafe naik-turun): daftar
        // lama tetap ditampilkan, dengan catatan bahwa ia mungkin sudah basi.
        setGalat(err instanceof Error ? err.message : 'Gagal memuat antrean.')
      }
      if (!batal) timer = window.setTimeout(muat, JEDA_MS)
    }

    muat()

    return () => {
      batal = true
      window.clearTimeout(timer)
    }
  }, [versi])

  async function terima(pesanan: Pesanan, cara: CaraBayar) {
    if (kirimId !== null) return

    setKirimId(pesanan.id)
    setGalat(null)
    try {
      const dibayar = await konfirmasiBayar(pesanan.id, cara)
      setAksi(null)
      // Langsung ke nota. Inilah satu-satunya saat kasir memegang pesanan yang
      // baru saja lunas — memintanya mencarinya lagi di daftar cuma menambah
      // langkah di detik paling sibuk.
      //
      // Yang dibawa cuma id-nya; notanya membaca ulang sendiri dari server.
      // Menitipkan salinan lewat state akan membuat alamat itu hidup HANYA
      // saat didatangi dari sini — persis yang bikin cetak ulang mustahil.
      navigate(`/nota/${dibayar.id}`)
    } catch (err) {
      if (err instanceof Error && err.message === SESI_HABIS) {
        keluarRef.current()
        return
      }
      setGalat(err instanceof Error ? err.message : 'Konfirmasi gagal. Coba lagi.')
      // Muat ulang HANYA saat gagal: 409 berarti pesanannya sudah berubah di
      // server (kedaluwarsa, atau kasir lain mendahului), dan daftar yang tak
      // disegarkan akan terus menawarkan tombol yang mustahil.
      setVersi((v) => v + 1)
    } finally {
      setKirimId(null)
    }
  }

  async function batalkan(pesanan: Pesanan) {
    if (kirimId !== null) return

    setKirimId(pesanan.id)
    setGalat(null)
    try {
      await batalkanPesanan(pesanan.id)
      setAksi(null)
    } catch (err) {
      if (err instanceof Error && err.message === SESI_HABIS) {
        keluarRef.current()
        return
      }
      setGalat(err instanceof Error ? err.message : 'Pembatalan gagal. Coba lagi.')
    } finally {
      setKirimId(null)
      setVersi((v) => v + 1)
    }
  }

  async function keluar() {
    await logout()
    keluarRef.current()
  }

  // Dihitung sekali per render, bukan di dalam map(): memeriksanya per kartu
  // berarti menyusuri seluruh daftar sekali untuk setiap barisnya.
  const kembar = totalKembar(daftar ?? [])

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
        <div>
          <h1 className="text-base font-semibold">Menunggu pembayaran</h1>
          <p className="text-sm text-slate-600">
            {daftar === null ? 'Memuat…' : `${daftar.length} pesanan`}
          </p>
        </div>
        {/* Bunyinya "perangkat ini", bukan "keluar dari semua": token yang
            sudah terbit di perangkat lain tak bisa dicabut (lihat
            SECURITY_TODO). Tombol harus jujur tentang apa yang dilakukannya. */}
        <div className="flex items-center gap-2">
          {/* Jalan menuju nota yang sudah dibayar. Ditaruh di sini, bukan di
              dalam daftar: pesanan yang lunas SUDAH TIDAK ADA di antrean, jadi
              tak ada baris mana pun yang bisa menuntun ke sana. */}
          <Link to="/riwayat" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
            Riwayat
          </Link>
          <button
            type="button"
            onClick={keluar}
            className="rounded-md border border-slate-300 px-3 py-2 text-left text-sm"
          >
            Keluar dari perangkat ini
          </button>
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
            Belum ada pesanan yang menunggu dibayar.
          </p>
        )}

        <ul className="flex flex-col gap-3">
          {daftar?.map((pesanan) => (
            <li key={pesanan.id} className="rounded-md border border-slate-200 bg-white p-4">
              <div className="flex items-baseline justify-between gap-3">
                {/* Meja berdiri sejajar nomor pesanan, bukan diselipkan di
                    baris nama: saat notifikasi mutasi cuma membawa nominal,
                    inilah kata pertama yang dicari kasir untuk menemukan
                    orangnya. */}
                <p className="text-lg font-semibold">
                  <span className="tabular-nums">{pesanan.order_number}</span>
                  {labelMeja(pesanan.meja, pesanan.tipe) && (
                    <span className="ml-2 text-slate-600">
                      · {labelMeja(pesanan.meja, pesanan.tipe)}
                    </span>
                  )}
                </p>
                <p className="text-sm text-slate-600">masuk {jam(pesanan.created_at)}</p>
              </div>
              <p className="text-sm text-slate-600">
                {pesanan.customer_name}
                {/* Niat pelanggan, bukan keputusan. Ditulis sebagai petunjuk
                    supaya kasir tak menganggapnya sudah pasti — orang berubah
                    pikiran di depan meja, dan yang masuk laporan harus yang
                    benar-benar diterima. */}
                {pesanan.niatBayar && (
                  <span className="text-slate-500">
                    {' · mau bayar '}
                    {CARA_BAYAR.find((c) => c.nilai === pesanan.niatBayar)?.label ??
                      pesanan.niatBayar}
                  </span>
                )}
              </p>

              <SisaWaktu menit={sisaMenit(pesanan.expires_at)} />

              <ul className="mt-3 flex flex-col gap-1">
                {pesanan.items.map((item, i) => (
                  // Kunci pakai indeks: satu pesanan bisa memuat produk yang
                  // sama dua baris dengan catatan berbeda, jadi product_id tak
                  // unik di sini. Daftar ini juga tak pernah diurut ulang.
                  <li key={i} className="flex justify-between gap-3 text-sm">
                    <span>
                      <span className="tabular-nums">{item.qty}×</span> {item.nama}
                      {item.note && (
                        <span className="block text-slate-600">— {item.note}</span>
                      )}
                    </span>
                    <span className="tabular-nums text-slate-600">{rupiah(item.total)}</span>
                  </li>
                ))}
              </ul>

              <div className="mt-3 flex items-center justify-between border-t border-slate-200 pt-3">
                <p className="text-sm text-slate-600">Total</p>
                <p className="text-lg font-semibold tabular-nums">{rupiah(pesanan.grand_total)}</p>
              </div>

              {/* Ditaruh tepat di bawah nominalnya, bukan di kepala kartu:
                  inilah angka yang sedang dibandingkan kasir dengan notifikasi
                  di HP-nya, jadi peringatannya harus ada di titik yang sama
                  dengan matanya. */}
              {kembar.has(pesanan.grand_total) && (
                <p className="mt-2 rounded-md bg-amber-100 px-2 py-1 text-sm text-amber-900">
                  Nominal sama dengan pesanan lain — cocokkan nama atau jam sebelum menerima.
                </p>
              )}

              {/* Tiga keadaan, dan dua di antaranya sengaja butuh dua sentuhan.
                  PAID dan CANCELLED sama-sama terminal — tak ada tombol "urungkan"
                  di seluruh sistem — jadi satu jempol nyasar tak boleh cukup
                  untuk keduanya. Untuk pembayaran, langkah kedua itu gratis:
                  cara bayar memang wajib dikirim ke server. */}
              {aksi?.id === pesanan.id && aksi.mode === 'bayar' ? (
                <div className="mt-3 flex flex-wrap gap-2">
                  {CARA_BAYAR.map((cara) => (
                    <button
                      key={cara.nilai}
                      type="button"
                      disabled={kirimId !== null}
                      onClick={() => terima(pesanan, cara.nilai)}
                      className="grow rounded-md bg-slate-900 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
                    >
                      {kirimId === pesanan.id ? 'Menyimpan…' : `Terima ${cara.label}`}
                    </button>
                  ))}
                  <button
                    type="button"
                    disabled={kirimId !== null}
                    onClick={() => setAksi(null)}
                    className="rounded-md border border-slate-300 px-4 py-3 text-base disabled:opacity-50"
                  >
                    Kembali
                  </button>
                </div>
              ) : aksi?.id === pesanan.id && aksi.mode === 'batal' ? (
                <div className="mt-3">
                  {/* Kalimatnya menyebut nomor pesanannya. Kasir yang membuka
                      konfirmasi ini karena salah pencet perlu satu petunjuk
                      konkret bahwa yang akan hangus BUKAN pesanan yang dia
                      kira. */}
                  <p role="alert" className="text-sm font-semibold text-red-700">
                    Batalkan pesanan {pesanan.order_number}? Tidak bisa dikembalikan.
                  </p>
                  <div className="mt-2 flex flex-wrap gap-2">
                    <button
                      type="button"
                      disabled={kirimId !== null}
                      onClick={() => batalkan(pesanan)}
                      className="grow rounded-md bg-red-700 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
                    >
                      {kirimId === pesanan.id ? 'Membatalkan…' : 'Ya, batalkan'}
                    </button>
                    <button
                      type="button"
                      disabled={kirimId !== null}
                      onClick={() => setAksi(null)}
                      className="rounded-md border border-slate-300 px-4 py-3 text-base disabled:opacity-50"
                    >
                      Kembali
                    </button>
                  </div>
                </div>
              ) : (
                <div className="mt-3 flex gap-2">
                  {/* Membatalkan diletakkan di kiri dan tanpa warna: ia bukan
                      tindakan yang dituju kasir sehari-hari, dan tombol merah
                      besar di samping jempol justru mengundang kecelakaan. */}
                  <button
                    type="button"
                    onClick={() => setAksi({ id: pesanan.id, mode: 'batal' })}
                    className="rounded-md border border-slate-300 px-4 py-3 text-base"
                  >
                    Batalkan
                  </button>
                  <button
                    type="button"
                    onClick={() => setAksi({ id: pesanan.id, mode: 'bayar' })}
                    className="grow rounded-md border border-slate-900 px-4 py-3 text-base font-semibold"
                  >
                    Konfirmasi bayar
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </main>
    </div>
  )
}

/**
 * Sisa waktu sebelum pesanan disapu jadi EXPIRED.
 *
 * Ini menutup risiko uang paling terbuka dari QRIS statis: pelanggan membayar
 * dari mejanya lalu diam, kasir tak pernah tahu ada yang perlu diperiksa, dan
 * `orders:expire` menghanguskan pesanan yang uangnya sudah masuk. Kasir tak
 * bisa mengejar tenggat yang tak terlihat.
 *
 * Yang sengaja TIDAK dilakukan: tombolnya tak pernah dinonaktifkan saat waktu
 * habis. Justru pesanan lewat-batas itulah yang paling mungkin sudah dibayar
 * diam-diam — melarang kasir menerimanya akan membalik risikonya, bukan
 * menghapusnya. Kalau `orders:expire` benar-benar sudah menyapunya, server
 * membalas 409 dan antrean menyegarkan diri sendiri.
 */
function SisaWaktu({ menit }: { menit: number | null }) {
  // Tenggat tak terbaca -> tak menampilkan apa pun. Baris "—" akan dibaca kasir
  // sebagai "pesanan ini tak punya batas waktu", padahal server tetap menyapunya.
  if (menit === null) return null

  if (menit > AMBANG_MENDESAK) {
    return <p className="mt-1 text-sm text-slate-600">Sisa {menit} mnt untuk dibayar</p>
  }

  const lewat = menit <= 0

  return (
    <p
      className={`mt-1 inline-block rounded-md px-2 py-1 text-sm font-semibold ${
        lewat ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900'
      }`}
    >
      {/* Kalimatnya menyuruh MEMERIKSA, bukan menolak — lihat catatan di atas. */}
      {lewat ? 'Lewat batas bayar · segera cek' : `Sisa ${menit} mnt · segera cek`}
    </p>
  )
}
