import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import {
  ambilSetelan,
  SESI_HABIS,
  simpanSetelan,
  unggahQris,
  urlQris,
  type Setelan,
} from './api'

/** Angka dari server -> isi kotak input. Yang tak terbaca jadi kosong. */
function teks(nilai: number): string {
  return Number.isFinite(nilai) ? String(nilai) : ''
}

/** Batas dari server -> atribut input. NaN dibuang supaya tak jadi "max=NaN". */
function batasInput(nilai: number): number | undefined {
  return Number.isFinite(nilai) ? nilai : undefined
}

/**
 * Setelan outlet — owner saja.
 *
 * Empat hal yang selama ini cuma bisa disetel lewat curl: QRIS, pajak, service
 * charge, dan batas waktu bayar. Selama layar ini tak ada, outlet baru lahir
 * tanpa baris order_settings sama sekali — dan pelanggan pertamanya membuka
 * layar status yang tak punya satu pun cara membayar.
 *
 * SENGAJA tanpa polling, sepola riwayat: ini tempat yang didatangi untuk
 * mengubah sesuatu. Memuat ulang di latar akan menimpa angka yang sedang
 * diketik owner.
 */
export default function LayarSetelan({ onKeluar }: { onKeluar: () => void }) {
  const [setelan, setSetelan] = useState<Setelan | null>(null)
  // Form disimpan sebagai TEKS, bukan angka: yang diketik owner adalah teks,
  // dan memaksanya jadi angka di tiap ketukan membuat kotak yang sedang
  // dikosongkan melompat kembali ke 0.
  const [form, setForm] = useState({
    pajak: '',
    layanan: '',
    kedaluwarsa: '',
    nama: '',
    alamat: '',
    telepon: '',
  })
  const [galat, setGalat] = useState<string | null>(null)
  const [kabar, setKabar] = useState<string | null>(null)
  const [sibuk, setSibuk] = useState(false)
  const [berkas, setBerkas] = useState<File | null>(null)

  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  /** Satu tempat menerima jawaban server, supaya form dan pratinjau tak pernah berselisih. */
  function terima(baru: Setelan) {
    setSetelan(baru)
    setForm({
      pajak: teks(baru.pajakPersen),
      layanan: teks(baru.layananPersen),
      kedaluwarsa: teks(baru.kedaluwarsaMenit),
      // null -> kotak kosong. Menampilkan "null" atau "-" di kotak yang bisa
      // diketik akan tersimpan apa adanya begitu owner menekan Simpan.
      nama: baru.namaOutlet ?? '',
      alamat: baru.alamatOutlet ?? '',
      telepon: baru.teleponOutlet ?? '',
    })
  }

  function tangani(err: unknown, cadangan: string) {
    if (err instanceof Error && err.message === SESI_HABIS) {
      keluarRef.current()
      return
    }
    setGalat(err instanceof Error ? err.message : cadangan)
  }

  useEffect(() => {
    let batal = false

    ambilSetelan()
      .then((hasil) => {
        if (batal) return
        terima(hasil)
      })
      .catch((err: unknown) => {
        if (batal) return
        tangani(err, 'Gagal memuat setelan.')
      })

    return () => {
      batal = true
    }
  }, [])

  async function simpan(e: React.FormEvent) {
    e.preventDefault()
    if (sibuk) return

    setSibuk(true)
    setGalat(null)
    setKabar(null)
    try {
      terima(
        await simpanSetelan({
          pajakPersen: Number(form.pajak),
          layananPersen: Number(form.layanan),
          kedaluwarsaMenit: Number(form.kedaluwarsa),
          namaOutlet: form.nama,
          alamatOutlet: form.alamat,
          teleponOutlet: form.telepon,
        }),
      )
      setKabar('Setelan tersimpan.')
    } catch (err) {
      tangani(err, 'Gagal menyimpan. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  async function unggah() {
    if (sibuk || berkas === null || setelan === null) return

    // Diperiksa di sini SEBELUM dikirim, memakai batas dari server: menunggu
    // 2 MB terkirim hanya untuk ditolak adalah cara paling lambat memberi tahu
    // owner sesuatu yang sudah bisa diketahui sejak berkasnya dipilih.
    const maksKb = setelan.batas.qris_max_kilobytes
    if (Number.isFinite(maksKb) && berkas.size > maksKb * 1024) {
      setGalat(`Gambar terlalu besar. Maksimal ${Math.round(maksKb / 1024)} MB.`)
      return
    }

    setSibuk(true)
    setGalat(null)
    setKabar(null)
    try {
      terima(await unggahQris(berkas))
      setBerkas(null)
      setKabar('QRIS baru terpasang.')
    } catch (err) {
      tangani(err, 'Gagal mengunggah. Coba lagi.')
    } finally {
      setSibuk(false)
    }
  }

  return (
    <div className="min-h-svh bg-slate-50 text-slate-900">
      <header className="sticky top-0 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <Link to="/" className="rounded-md border border-slate-300 px-3 py-2 text-sm">
          ← Antrean
        </Link>
        <h1 className="text-base font-semibold">Setelan outlet</h1>
      </header>

      <main className="mx-auto max-w-2xl px-4 py-4">
        {galat && (
          <p role="alert" className="mb-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
            {galat}
          </p>
        )}
        {kabar && (
          <p
            role="status"
            className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
          >
            {kabar}
          </p>
        )}

        {setelan === null ? (
          <p className="py-16 text-center text-sm text-slate-600">Memuat setelan…</p>
        ) : (
          <div className="flex flex-col gap-4">
            <section className="rounded-md border border-slate-200 bg-white p-4">
              <h2 className="text-base font-semibold">Cara bayar QRIS</h2>
              <p className="mt-1 text-sm text-slate-600">
                Gambar ini yang muncul di HP pelanggan saat menunggu pembayaran.
              </p>

              {setelan.qrisUrl ? (
                // Pratinjau, bukan sekadar tulisan "sudah terpasang": satu-satunya
                // cara owner tahu dia mengunggah berkas yang benar adalah melihatnya.
                <img
                  src={urlQris(setelan.qrisUrl)}
                  alt="QRIS outlet yang sedang terpasang"
                  className="mt-3 h-48 w-48 rounded-md border border-slate-200 object-contain"
                />
              ) : (
                <p className="mt-3 rounded-md bg-amber-100 px-3 py-2 text-sm text-amber-900">
                  Belum ada QRIS. Pelanggan diminta membayar di kasir.
                </p>
              )}

              <input
                type="file"
                // Daftar-izin yang sama dengan server. Ini cuma menyaring dialog
                // berkas — yang benar-benar memeriksa isi berkas tetap server.
                accept="image/png,image/jpeg"
                onChange={(e) => {
                  setBerkas(e.target.files?.[0] ?? null)
                  setGalat(null)
                }}
                className="mt-3 block w-full text-sm"
              />
              <button
                type="button"
                disabled={sibuk || berkas === null}
                onClick={unggah}
                className="mt-3 rounded-md bg-slate-900 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
              >
                {sibuk ? 'Mengunggah…' : 'Ganti QRIS'}
              </button>
            </section>

            <form onSubmit={simpan} className="rounded-md border border-slate-200 bg-white p-4">
              <h2 className="text-base font-semibold">Identitas kafe</h2>
              <p className="mt-1 text-sm text-slate-600">
                Dicetak di kepala struk. Yang dikosongkan tidak ikut tercetak.
              </p>

              {/* Tak satu pun wajib: struk tanpa nama masih berguna, dan
                  memaksa owner mengisi alamat sebelum boleh menyimpan pajak
                  akan menahan hal yang mendesak demi hal yang tidak. */}
              <label className="mt-3 block text-sm">
                Nama kafe
                <input
                  type="text"
                  maxLength={batasInput(setelan.batas.outlet_name_max)}
                  placeholder="Kopi Senja"
                  value={form.nama}
                  onChange={(e) => setForm({ ...form, nama: e.target.value })}
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base"
                />
              </label>

              <label className="mt-3 block text-sm">
                Alamat
                <input
                  type="text"
                  maxLength={batasInput(setelan.batas.outlet_address_max)}
                  placeholder="Jl. Contoh No. 123"
                  value={form.alamat}
                  onChange={(e) => setForm({ ...form, alamat: e.target.value })}
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base"
                />
              </label>

              <label className="mt-3 block text-sm">
                Telepon
                <input
                  // type="text", bukan "tel": nomor kafe ditulis dengan segala
                  // macam gaya (+62, spasi, dua nomor sekaligus) dan isinya
                  // cuma dicetak, tak pernah dipanggil sistem.
                  type="text"
                  maxLength={batasInput(setelan.batas.outlet_phone_max)}
                  placeholder="0812-3456-7890"
                  value={form.telepon}
                  onChange={(e) => setForm({ ...form, telepon: e.target.value })}
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base"
                />
              </label>

              <h2 className="mt-6 border-t border-slate-200 pt-4 text-base font-semibold">
                Tarif &amp; batas waktu
              </h2>

              {/* min/max diambil dari server, tidak ditulis di sini. Validasi
                  bawaan browser memberi peringatan dalam bahasa perangkat owner
                  sebelum satu pun permintaan dikirim — dan yang menolak
                  sungguhan tetap server. */}
              <label className="mt-3 block text-sm">
                Pajak (%)
                <input
                  type="number"
                  required
                  min={0}
                  max={batasInput(setelan.batas.tax_percent_max)}
                  step="0.01"
                  value={form.pajak}
                  onChange={(e) => setForm({ ...form, pajak: e.target.value })}
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base tabular-nums"
                />
              </label>

              <label className="mt-3 block text-sm">
                Service charge (%)
                <input
                  type="number"
                  required
                  min={0}
                  max={batasInput(setelan.batas.service_charge_percent_max)}
                  step="0.01"
                  value={form.layanan}
                  onChange={(e) => setForm({ ...form, layanan: e.target.value })}
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base tabular-nums"
                />
              </label>

              <label className="mt-3 block text-sm">
                Batas waktu bayar (menit)
                <input
                  type="number"
                  required
                  min={batasInput(setelan.batas.order_expiry_minutes_min)}
                  max={batasInput(setelan.batas.order_expiry_minutes_max)}
                  step="1"
                  value={form.kedaluwarsa}
                  onChange={(e) => setForm({ ...form, kedaluwarsa: e.target.value })}
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-base tabular-nums"
                />
                <span className="mt-1 block text-slate-600">
                  Setelah lewat, pesanan yang belum dibayar hangus sendiri.
                </span>
              </label>

              <button
                type="submit"
                disabled={sibuk}
                className="mt-4 w-full rounded-md bg-slate-900 px-4 py-3 text-base font-semibold text-white disabled:opacity-50"
              >
                {sibuk ? 'Menyimpan…' : 'Simpan setelan'}
              </button>
            </form>
          </div>
        )}
      </main>
    </div>
  )
}
