import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import {
  MAKS_ITEM,
  MAKS_QTY,
  NAMA_KAFE,
  ambilMeja,
  ambilMenu,
  kirimPesanan,
  susunPesanan,
  type Kategori,
  type Meja,
} from './api'

/** Rupiah tanpa desimal — uang di sistem ini integer rupiah, bukan pecahan. */
function rupiah(nilai: number): string {
  return 'Rp ' + new Intl.NumberFormat('id-ID').format(nilai)
}

/**
 * Wadah gambar menu, 64x64.
 *
 * Ukuran DIKUNCI lewat object-cover: foto kafe datang dengan rasio semaunya
 * (potret dari HP, lanskap dari kamera) — tanpa ini tinggi baris jadi
 * loncat-loncat dan daftar terasa berantakan saat di-scroll.
 *
 * alt="" disengaja: nama menunya sudah tertulis tepat di sebelahnya, jadi
 * pembaca layar tak perlu mendengarnya dua kali.
 */
function GambarMenu({ src }: { src: string | null }) {
  if (!src) {
    return (
      <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-slate-100">
        <svg
          className="h-6 w-6 text-slate-400"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          aria-hidden="true"
        >
          <rect x="3" y="4" width="18" height="16" rx="2" />
          <circle cx="8.5" cy="9.5" r="1.5" />
          <path d="m3 16 5-4 4 3 3-2 6 5" />
        </svg>
      </div>
    )
  }

  return (
    <img
      src={src}
      alt=""
      loading="lazy"
      className="h-16 w-16 shrink-0 rounded-md border border-slate-200 object-cover"
    />
  )
}

/** Bungkus pesan satu layar — dipakai untuk memuat dan galat. */
function Pesan({ judul, isi }: { judul: string; isi: string }) {
  return (
    <div className="mx-auto max-w-md px-4 py-16 text-center">
      <p className="text-base font-semibold">{judul}</p>
      <p className="mt-1 text-sm text-slate-600">{isi}</p>
    </div>
  )
}

type Status = 'memuat' | 'siap' | 'galat'

export default function HalamanMenu() {
  const { qrToken } = useParams<{ qrToken: string }>()

  const [status, setStatus] = useState<Status>('memuat')
  const [meja, setMeja] = useState<Meja | null>(null)
  const [kategori, setKategori] = useState<Kategori[]>([])
  const [qty, setQty] = useState<Record<string, number>>({})
  const [cari, setCari] = useState('')

  // Tahap konfirmasi ditampilkan sebagai layar pengganti, bukan rute baru:
  // keranjangnya hidup di state komponen ini, dan pindah rute berarti harus
  // mengangkat state itu ke atas. ponytail: kalau tombol back HP nanti terasa
  // salah, naikkan ke rute /t/:qrToken/pesan.
  const [tahap, setTahap] = useState<'menu' | 'konfirmasi'>('menu')
  const [nama, setNama] = useState('')
  const [mengirim, setMengirim] = useState(false)
  const [galatKirim, setGalatKirim] = useState('')
  const navigate = useNavigate()

  // DUA panggilan berurutan, bukan satu: `GET /t/{qr}` tak mengembalikan menu,
  // dan tenant_id-nya baru diketahui setelah panggilan pertama selesai.
  useEffect(() => {
    if (!qrToken) return

    // Penanda batal: React StrictMode menjalankan efek dua kali saat dev, dan
    // pelanggan bisa menutup halaman sebelum jaringan selesai. Tanpa ini,
    // hasil yang sudah basi bisa menimpa state.
    let batal = false
    setStatus('memuat')

    ambilMeja(qrToken)
      .then(async (m) => {
        const k = await ambilMenu(m.tenant_id)
        if (batal) return
        setMeja(m)
        setKategori(k)
        setStatus('siap')
      })
      .catch(() => {
        if (!batal) setStatus('galat')
      })

    return () => {
      batal = true
    }
  }, [qrToken])

  // Filter di sisi HP, bukan endpoint pencarian baru: /api/menu sudah mengirim
  // seluruh menu tenant sekaligus, jadi datanya memang sudah ada di tangan.
  const kunci = cari.trim().toLowerCase()
  const tampil =
    kunci === ''
      ? kategori
      : kategori
          .map((k) => ({
            ...k,
            produk: k.produk.filter(
              (p) =>
                p.nama.toLowerCase().includes(kunci) ||
                p.deskripsi.toLowerCase().includes(kunci),
            ),
          }))
          .filter((k) => k.produk.length > 0)

  const ubahQty = (id: string, delta: number) =>
    setQty((lama) => ({
      ...lama,
      [id]: Math.min(MAKS_QTY, Math.max(0, (lama[id] ?? 0) + delta)),
    }))

  /**
   * Qty diketik langsung. Dijepit di sini, BUKAN dibiarkan sampai server:
   * kalau pelanggan mengetik 500, server menolak dengan 422 setelah dia
   * susah payah mengisi keranjang. Lebih baik angkanya tak pernah bisa salah.
   */
  const ketikQty = (id: string, teks: string) => {
    // Keyboard HP masih bisa mengirim '-', '+', 'e', atau spasi walau numerik.
    const digit = teks.replace(/\D/g, '')
    const angka = digit === '' ? 0 : Math.min(MAKS_QTY, Number(digit))
    setQty((lama) => ({ ...lama, [id]: angka }))
  }

  // Dihitung dari SELURUH menu, bukan dari `tampil`: item yang sedang
  // tersembunyi oleh pencarian tetap ada di keranjang dan tetap harus dibayar.
  const semuaProduk = kategori.flatMap((k) => k.produk)
  const totalItem = Object.values(qty).reduce((a, b) => a + b, 0)
  const totalHarga = semuaProduk.reduce(
    (jumlah, p) => jumlah + p.harga * (qty[p.id] ?? 0),
    0,
  )

  // Item yang dipesan, lengkap dengan datanya — dipakai layar ringkasan.
  const dipesan = semuaProduk.filter((p) => (qty[p.id] ?? 0) > 0)

  const kirim = async () => {
    if (!qrToken) return
    setMengirim(true)
    setGalatKirim('')
    try {
      const pesanan = await kirimPesanan(susunPesanan({ qrToken, nama, qty }))
      navigate(`/order/${pesanan.id}`)
    } catch (e) {
      setGalatKirim(e instanceof Error ? e.message : 'Pesanan gagal dikirim.')
      setMengirim(false)
    }
  }

  if (status === 'memuat') {
    return <Pesan judul="Memuat menu…" isi="Sebentar ya." />
  }

  if (status === 'galat') {
    return (
      <Pesan
        judul="Menu tidak bisa dimuat"
        isi="Periksa koneksi, lalu scan ulang QR di meja. Kalau tetap gagal, panggil kasir."
      />
    )
  }

  if (tahap === 'konfirmasi') {
    const namaValid = nama.trim().length > 0 && nama.trim().length <= 100
    const terlaluBanyak = dipesan.length > MAKS_ITEM

    return (
      <div className="min-h-svh bg-white text-slate-900">
        <header className="flex items-center gap-3 border-b border-slate-200 px-4 py-3">
          <button
            type="button"
            onClick={() => setTahap('menu')}
            className="h-11 rounded-md border border-slate-300 px-3 text-base"
          >
            ← Menu
          </button>
          <h1 className="text-[17px] font-semibold">Periksa pesanan</h1>
        </header>

        <main className="mx-auto max-w-md px-4 pt-4 pb-28">
          {/* Ringkasan menampilkan SELURUH keranjang, termasuk item yang tadi
              tersembunyi oleh pencarian — di sinilah pelanggan memastikan
              tak ada yang salah sebelum uang bergerak. */}
          <ul className="flex flex-col gap-3">
            {dipesan.map((p) => (
              <li key={p.id} className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <p className="text-base font-semibold">{p.nama}</p>
                  <p className="text-sm text-slate-600">
                    {qty[p.id]} × {rupiah(p.harga)}
                  </p>
                </div>
                <p className="text-base font-semibold tabular-nums">
                  {rupiah(p.harga * qty[p.id])}
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

          {/* Instruksi bayar sengaja hardcode — belum ada tempatnya di API. */}
          <div className="mt-6 rounded-md bg-slate-50 p-3">
            <p className="text-sm font-semibold">Cara bayar</p>
            <p className="mt-1 text-sm text-slate-600">
              Lakukan pembayaran dengan scan QRIS di meja kasir. Pesanan mulai
              dibuat setelah kasir memastikan pembayaranmu masuk.
            </p>
          </div>

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
            disabled={!namaValid || terlaluBanyak || mengirim || dipesan.length === 0}
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

  return (
    <div className="min-h-svh bg-white text-slate-900">
      <header className="border-b border-slate-200 px-4 py-3">
        <h1 className="text-[17px] font-semibold">{NAMA_KAFE}</h1>
        <p className="text-sm text-slate-600">{meja?.label}</p>
      </header>

      {/* pb-28: ruang supaya item terakhir tak tertutup bar cart yang melayang. */}
      <main className="mx-auto max-w-md px-4 pt-4 pb-28">
        {/* sticky: tetap terjangkau saat daftar panjang di-scroll — percuma punya
            pencarian kalau harus scroll balik ke atas untuk memakainya. */}
        <div className="sticky top-0 z-10 -mx-4 bg-white px-4 pb-3">
          {/* type=search memberi tombol hapus (x) bawaan HP dan tombol "cari"
              di keyboard — gratis, tak perlu dibuat sendiri. */}
          <input
            type="search"
            value={cari}
            onChange={(e) => setCari(e.target.value)}
            placeholder="Cari menu…"
            aria-label="Cari menu"
            className="h-11 w-full rounded-md border border-slate-300 px-3 text-base placeholder:text-slate-400"
          />
        </div>

        {tampil.length === 0 && (
          <div className="py-10 text-center">
            <p className="text-base font-semibold">
              {kunci === '' ? 'Menu belum tersedia' : 'Menu tidak ditemukan'}
            </p>
            <p className="mt-1 text-sm text-slate-600">
              {kunci === ''
                ? 'Kafe ini belum mengisi menunya. Panggil kasir untuk memesan.'
                : 'Coba kata lain, atau hapus pencarian.'}
            </p>
            {kunci !== '' && (
              <button
                type="button"
                onClick={() => setCari('')}
                className="mt-4 h-11 rounded-md border border-slate-300 px-4 text-base font-semibold"
              >
                Hapus pencarian
              </button>
            )}
          </div>
        )}

        {tampil.map((k) => (
          <section key={k.id} className="mb-6">
            <h2 className="mb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase">
              {k.nama}
            </h2>

            <ul className="flex flex-col gap-3">
              {k.produk.map((p) => (
                <li key={p.id} className="rounded-md border border-slate-200 p-3">
                  <div className="flex items-start gap-3">
                    <GambarMenu src={p.gambarUrl} />

                    {/* flex-1 + min-w-0: teks boleh menyusut, TAK boleh mendorong
                        tombol qty keluar layar saat nama menunya panjang. */}
                    <div className="min-w-0 flex-1">
                      <p className="text-[17px] font-semibold">{p.nama}</p>
                      {/* line-clamp-2: deskripsi panjang dipotong, tinggi baris tetap seragam. */}
                      <p className="mt-0.5 line-clamp-2 text-sm text-slate-600">
                        {p.deskripsi}
                      </p>
                      <p className="mt-1.5 text-base font-semibold">{rupiah(p.harga)}</p>
                    </div>
                  </div>

                  {/* Kontrol qty PINDAH ke baris sendiri: kolom ketik menambah ~48px,
                      dan di HP 360px tiga kolom menyisakan cuma ~72px untuk nama menu.
                      Tombol tak boleh dikecilkan (44px batas sentuh), jadi barisnya
                      yang dipecah. */}
                  <div className="mt-3 flex items-center justify-end gap-1">
                    <button
                      type="button"
                      onClick={() => ubahQty(p.id, -1)}
                      disabled={(qty[p.id] ?? 0) === 0}
                      aria-label={`Kurangi ${p.nama}`}
                      className="h-11 w-11 rounded-md border border-slate-300 text-xl leading-none disabled:opacity-40"
                    >
                      −
                    </button>
                    {/* type=text + inputMode=numeric, BUKAN type=number: yang terakhir
                        membawa panah spinner dan tetap meloloskan 'e' & '-' di sebagian
                        browser. Ini memunculkan keyboard angka tanpa bawaan itu. */}
                    <input
                      type="text"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      value={(qty[p.id] ?? 0) === 0 ? '' : String(qty[p.id])}
                      onChange={(e) => ketikQty(p.id, e.target.value)}
                      placeholder="0"
                      aria-label={`Jumlah ${p.nama}`}
                      className="h-11 w-14 rounded-md border border-slate-300 text-center text-base font-semibold tabular-nums"
                    />
                    <button
                      type="button"
                      onClick={() => ubahQty(p.id, 1)}
                      aria-label={`Tambah ${p.nama}`}
                      className="h-11 w-11 rounded-md border border-slate-300 text-xl leading-none"
                    >
                      +
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </main>

      {/* Shadow di sini FUNGSIONAL: memisahkan bar dari daftar yang lewat di bawahnya. */}
      {totalItem > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-2px_8px_rgba(0,0,0,0.06)]">
          <div className="mx-auto flex max-w-md items-center gap-3">
            <div className="min-w-0 flex-1">
              <p className="text-sm text-slate-600">{totalItem} item</p>
              <p className="text-base font-semibold">{rupiah(totalHarga)}</p>
            </div>
            <button
              type="button"
              onClick={() => setTahap('konfirmasi')}
              className="h-12 flex-1 rounded-md bg-amber-700 text-base font-semibold text-white active:bg-amber-800"
            >
              Pesan sekarang
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
