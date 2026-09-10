import { useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { MAKS_ITEM, kirimPesanan, susunPesanan, type CaraBayar } from './api'
import { rupiah } from './format'
import { useMeja } from './konteksMeja'
import { catatPesanan, kunciPesanan } from './pesananSaya'

/**
 * Layar periksa-sebelum-kirim: /t/:qrToken/pesan.
 *
 * Punya alamat sendiri, bukan penanda di state. Bedanya nyata buat pelanggan:
 * refresh tetap di sini (bukan terlempar balik ke menu), tombol back HP
 * kembali ke menu (bukan keluar dari app), dan tautannya bisa dibagikan ke
 * teman semeja.
 */
/**
 * Kosakata server ('cash'/'qris_static') diterjemahkan ke bahasa pelanggan di
 * sini — satu-satunya tempat penerjemahan itu boleh terjadi. Keterangannya
 * bukan hiasan: pilihan ini menentukan apa yang pelanggan lihat berikutnya.
 */
const CARA_BAYAR: Array<{ nilai: CaraBayar; label: string; jelas: string }> = [
  { nilai: 'cash', label: 'Tunai', jelas: 'Bayar langsung di meja kasir.' },
  { nilai: 'qris_static', label: 'E-Payment', jelas: 'Scan QRIS dari HP-mu, tanpa ke kasir.' },
]

export default function HalamanRingkasan() {
  const { qrToken, kategori, isi, hapusKeranjang } = useMeja()
  const navigate = useNavigate()

  const [nama, setNama] = useState('')
  // Sengaja TANPA nilai awal. Memilihkan diam-diam berarti sebagian orang
  // mengirim pesanan dengan cara bayar yang tak pernah mereka baca — dan
  // pilihan ini menentukan apakah layar berikutnya menyodorkan QR atau
  // menyuruhnya berjalan ke kasir.
  const [caraBayar, setCaraBayar] = useState<CaraBayar | null>(null)
  const [mengirim, setMengirim] = useState(false)
  // Guard SINKRON untuk double-tap: setState reaktif baru mematikan tombol pada
  // render berikutnya — dua klik di tick yang sama tetap jalan tanpa ini.
  const kirimRef = useRef(false)
  const [galatKirim, setGalatKirim] = useState('')

  // Menandai bahwa keranjang dikosongkan SENGAJA, bukan karena pelanggan
  // menghapus isinya. Tanpa ini, pembersihan setelah kirim sukses memicu
  // penjaga "keranjang kosong" di bawah dan melempar pelanggan balik ke menu
  // sebelum sempat sampai ke halaman status.
  const [terkirim, setTerkirim] = useState(false)

  const semuaProduk = kategori.flatMap((k) => k.produk)
  const dipesan = semuaProduk.filter((p) => (isi[p.id]?.qty ?? 0) > 0)
  const totalHarga = dipesan.reduce((jumlah, p) => jumlah + p.harga * isi[p.id].qty, 0)

  // Halaman periksa-pesanan tanpa pesanan cuma tombol mati yang membingungkan.
  // Terjadi kalau alamat ini dibuka langsung, atau di-refresh setelah
  // pesanannya sudah terkirim.
  if (dipesan.length === 0 && !terkirim) {
    return <Navigate to=".." replace />
  }

  const namaValid = nama.trim().length > 0 && nama.trim().length <= 100
  const terlaluBanyak = dipesan.length > MAKS_ITEM

  const kirim = async () => {
    // Penjaga kedua, bukan cuma tombol yang mati: state bisa berubah di antara
    // render dan klik, dan payload tanpa cara bayar akan ditolak server.
    if (kirimRef.current || caraBayar === null) return

    kirimRef.current = true
    setMengirim(true)
    setGalatKirim('')
    try {
      const pesanan = await kirimPesanan(susunPesanan({ qrToken, nama, isi, caraBayar }))
      // HANYA setelah server menerimanya. Kalau pengiriman gagal, keranjang
      // justru wajib bertahan — itu seluruh alasan ia disimpan.
      setTerkirim(true)
      hapusKeranjang()
      // Dicatat SEBELUM pindah layar, sejajar dengan pembersihan keranjang:
      // keduanya harus terjadi pada commit yang sama dengan pengiriman yang
      // sukses. Ditaruh di effect, ia takkan pernah jalan — komponen ini
      // di-unmount pada commit itu juga.
      catatPesanan(kunciPesanan(qrToken), pesanan.id)
      // replace: pelanggan yang menekan back dari halaman status tak perlu
      // mendarat di ringkasan pesanan yang sudah terkirim.
      navigate(`/t/${qrToken}/order/${pesanan.id}`, { replace: true })
    } catch (e) {
      kirimRef.current = false
      setGalatKirim(e instanceof Error ? e.message : 'Pesanan gagal dikirim.')
      setMengirim(false)
    }
  }

  return (
    <div className="min-h-svh bg-white text-slate-900">
      <header className="flex items-center gap-3 border-b border-slate-200 px-4 py-3">
        {/* Link, bukan tombol ber-onClick: pelanggan bisa menekannya lama
            untuk membuka di tab lain, dan riwayat browser mencatatnya. */}
        <Link
          to=".."
          className="flex h-11 items-center rounded-md border border-slate-300 px-3 text-base"
        >
          ← Menu
        </Link>
        <h1 className="text-[17px] font-semibold">Periksa pesanan</h1>
      </header>

      <main className="mx-auto max-w-md px-4 pt-4 pb-28">
        {/* Ringkasan menampilkan SELURUH keranjang, termasuk item yang tadi
            tersembunyi oleh pencarian di halaman menu — di sinilah pelanggan
            memastikan tak ada yang salah sebelum uang bergerak. */}
        <ul className="flex flex-col gap-3">
          {dipesan.map((p) => (
            <li key={p.id} className="flex items-start justify-between gap-3">
              <div className="min-w-0 flex-1">
                <p className="text-base font-semibold">{p.nama}</p>
                <p className="text-sm text-slate-600">
                  {isi[p.id].qty} × {rupiah(p.harga)}
                </p>
                {/* Catatan ditampilkan UTUH di sini, tak dipotong seperti di
                    kartu menu: ini layar terakhir sebelum pesanan berangkat,
                    satu-satunya tempat pelanggan bisa menyadari catatannya
                    nyasar ke menu yang salah. */}
                {isi[p.id].note && (
                  <p className="mt-0.5 text-sm break-words text-amber-800">
                    Catatan: {isi[p.id].note}
                  </p>
                )}
              </div>
              <p className="text-base font-semibold tabular-nums">
                {rupiah(p.harga * isi[p.id].qty)}
              </p>
            </li>
          ))}
        </ul>

        {/* "Subtotal menu", BUKAN "Total". Server menghitung ulang pakai
            OrderCalculator: pajak & service charge ditambahkan, promo
            dikurangi. Menyebut angka ini "Total" berarti menjanjikan sesuatu
            yang belum tentu ditagih — pelanggan merasa dikadalin di kasir. */}
        <div className="mt-4 flex items-center justify-between border-t border-slate-200 pt-4">
          <p className="text-base font-semibold">Subtotal menu</p>
          <p className="text-[17px] font-semibold tabular-nums">{rupiah(totalHarga)}</p>
        </div>
        <p className="mt-1 text-sm text-slate-600">
          Pajak, biaya layanan, dan promo dihitung saat pesanan dibuat. Rincian
          lengkapnya muncul di halaman berikutnya.
        </p>

        <label className="mt-6 block">
          <span className="text-sm font-semibold">Nama pemesan</span>
          <input
            type="text"
            value={nama}
            onChange={(e) => setNama(e.target.value)}
            maxLength={100}
            placeholder="Nama kamu"
            autoComplete="name"
            className="mt-1.5 h-11 w-full rounded-md border border-slate-300 px-3 text-base placeholder:text-slate-400"
          />
          <span className="mt-1 block text-sm text-slate-600">
            Dipakai kasir untuk memanggil pesananmu.
          </span>
        </label>

        {/* radio sungguhan yang disembunyikan, bukan div ber-onClick: panah
            keyboard berpindah pilihan, pembaca layar mengumumkan "1 dari 2",
            dan statusnya terbaca tanpa perlu atribut aria buatan. */}
        <fieldset className="mt-6">
          <legend className="text-sm font-semibold">Cara bayar</legend>

          <div className="mt-2 flex flex-col gap-2">
            {CARA_BAYAR.map((cara) => (
              <label
                key={cara.nilai}
                className={`flex cursor-pointer flex-col rounded-md border p-3 ${
                  caraBayar === cara.nilai
                    ? 'border-amber-700 bg-amber-50'
                    : 'border-slate-300'
                }`}
              >
                <input
                  type="radio"
                  name="cara-bayar"
                  value={cara.nilai}
                  checked={caraBayar === cara.nilai}
                  onChange={() => setCaraBayar(cara.nilai)}
                  className="sr-only"
                />
                <span className="text-base font-semibold">{cara.label}</span>
                <span className="text-sm text-slate-600">{cara.jelas}</span>
              </label>
            ))}
          </div>

          <p className="mt-2 text-sm text-slate-600">
            Pesanan mulai dibuat setelah kasir memastikan pembayaranmu masuk.
          </p>
        </fieldset>

        {terlaluBanyak && (
          <p className="mt-4 text-sm font-semibold text-red-700">
            Maksimal {MAKS_ITEM} jenis menu per pesanan. Kurangi dulu, atau pesan
            dalam dua kali.
          </p>
        )}

        {galatKirim && (
          <p className="mt-4 text-sm font-semibold text-red-700">{galatKirim}</p>
        )}
      </main>

      <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
        <button
          type="button"
          onClick={kirim}
          disabled={!namaValid || caraBayar === null || terlaluBanyak || mengirim}
          className="mx-auto flex h-12 w-full max-w-md items-center justify-center rounded-md bg-amber-700 text-base font-semibold text-white active:bg-amber-800 disabled:opacity-40"
        >
          {/* Angka sengaja DIHILANGKAN dari tombol: menempelkan nominal di
              tombol aksi = janji, dan yang menagih adalah server. */}
          {mengirim ? 'Mengirim…' : 'Kirim pesanan'}
        </button>
      </div>
    </div>
  )
}
