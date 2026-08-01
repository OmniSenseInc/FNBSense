import { useState } from 'react'
import { Link } from 'react-router'
import { NAMA_KAFE, type Produk } from './api'
import DialogPesananSaya from './DialogPesananSaya'
import DialogProduk from './DialogProduk'
import { rupiah } from './format'
import KontrolQty from './KontrolQty'
import { ubahBaris } from './keranjang'
import { useMeja } from './konteksMeja'
import { bacaPesananSaya, kunciPesanan } from './pesananSaya'

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

/**
 * Daftar menu satu meja: /t/:qrToken.
 *
 * Meja, menu, dan keranjang datang dari rute induk — halaman ini murni
 * memilih dan menghitung, tak mengambil apa pun sendiri.
 */
export default function HalamanMenu() {
  const { qrToken, meja, kategori, isi, setIsi } = useMeja()

  const [cari, setCari] = useState('')

  /**
   * Dibaca SEKALI saat halaman dipasang, bukan tiap render.
   *
   * Halaman ini di-render ulang tiap ketukan +/− dan tiap huruf yang diketik di
   * kotak cari; membaca localStorage di jalur itu berarti mem-parse JSON puluhan
   * kali per menit untuk data yang cuma berubah saat pesanan baru dikirim — dan
   * pesanan baru selalu datang lewat layar LAIN, yang memasang ulang halaman ini
   * saat pelanggan kembali.
   */
  const [pesananSaya] = useState(() => bacaPesananSaya(kunciPesanan(qrToken)))
  const [pesananDibuka, setPesananDibuka] = useState(false)

  // Menu yang sedang dibuka detailnya. null = tak ada lembar terbuka.
  const [produkDibuka, setProdukDibuka] = useState<Produk | null>(null)

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

  // Satu-satunya pintu mengubah keranjang di halaman ini. Aturannya (catatan
  // bertahan saat jumlah berubah, qty nol menghapus barisnya) ada di
  // keranjang.ts supaya bisa diuji tanpa merender apa pun.
  const ubah = (id: string, qty: number, note?: string) =>
    setIsi((lama) => ubahBaris(lama, id, qty, note))

  // Dihitung dari SELURUH menu, bukan dari `tampil`: item yang sedang
  // tersembunyi oleh pencarian tetap ada di keranjang dan tetap harus dibayar.
  const semuaProduk = kategori.flatMap((k) => k.produk)
  const totalItem = Object.values(isi).reduce((a, b) => a + b.qty, 0)
  const totalHarga = semuaProduk.reduce(
    (jumlah, p) => jumlah + p.harga * (isi[p.id]?.qty ?? 0),
    0,
  )

  return (
    <div className="min-h-svh bg-white text-slate-900">
      <header className="flex items-center gap-3 border-b border-slate-200 px-4 py-3">
        <div className="min-w-0 flex-1">
          <h1 className="text-[17px] font-semibold">{NAMA_KAFE}</h1>
          <p className="text-sm text-slate-600">{meja.label}</p>
        </div>

        {/* Muncul HANYA kalau HP ini memang pernah memesan di meja ini hari
            ini. Tombol yang selalu ada lalu membuka popup kosong mengajari
            pelanggan bahwa tombol itu tak berguna, dan dia berhenti melihatnya
            justru ketika akhirnya ada isinya. */}
        {pesananSaya.length > 0 && (
          <button
            type="button"
            onClick={() => setPesananDibuka(true)}
            className="h-11 shrink-0 rounded-md border border-slate-300 px-3 text-sm font-semibold"
          >
            Pesanan saya ({pesananSaya.length})
          </button>
        )}
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
                ? 'Kafe ini belum mengisi menunya. Pergi ke kasir untuk memesan.'
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
              {k.produk.map((p) => {
                const baris = isi[p.id]
                return (
                <li key={p.id} className="rounded-md border border-slate-200 p-3">
                  {/* HANYA baris ini yang jadi tombol, bukan seluruh kartu:
                      kalau kartunya yang diklik, menekan +/− ikut membuka
                      lembar detail karena klik merambat ke induknya. */}
                  <button
                    type="button"
                    onClick={() => setProdukDibuka(p)}
                    className="flex w-full items-start gap-3 text-left"
                  >
                    <GambarMenu src={p.gambarUrl} />

                    {/* flex-1 + min-w-0: teks boleh menyusut, TAK boleh mendorong
                        tombol qty keluar layar saat nama menunya panjang. */}
                    <div className="min-w-0 flex-1">
                      <p className="text-[17px] font-semibold">{p.nama}</p>
                      {/* line-clamp-2: deskripsi panjang dipotong, tinggi baris tetap
                          seragam. Versi utuhnya dibaca di lembar detail. */}
                      <p className="mt-0.5 line-clamp-2 text-sm text-slate-600">
                        {p.deskripsi}
                      </p>
                      <p className="mt-1.5 text-base font-semibold">{rupiah(p.harga)}</p>
                      {/* Catatan ikut terlihat TANPA membuka lembar detail:
                          pelanggan yang menulis "tanpa gula" lalu scroll jauh
                          tak punya cara lain memastikan permintaannya nyantol. */}
                      {baris?.note && (
                        <p className="mt-1 truncate text-sm text-amber-800">
                          Catatan: {baris.note}
                        </p>
                      )}
                    </div>
                  </button>

                  {/* Kontrol qty PINDAH ke baris sendiri: kolom ketik menambah ~48px,
                      dan di HP 360px tiga kolom menyisakan cuma ~72px untuk nama menu.
                      Tombol tak boleh dikecilkan (44px batas sentuh), jadi barisnya
                      yang dipecah. */}
                  <div className="mt-3 flex justify-end">
                    <KontrolQty
                      nilai={baris?.qty ?? 0}
                      onUbah={(n) => ubah(p.id, n)}
                      label={p.nama}
                    />
                  </div>
                </li>
                )
              })}
            </ul>
          </section>
        ))}
      </main>

      <DialogPesananSaya
        entri={pesananSaya}
        qrToken={qrToken}
        terbuka={pesananDibuka}
        onTutup={() => setPesananDibuka(false)}
      />

      {/* Selalu ter-render, isinya kosong saat tertutup: <dialog> butuh ref
          yang stabil supaya showModal() punya sasaran saat menu dipilih. */}
      <DialogProduk
        produk={produkDibuka}
        qtySekarang={produkDibuka ? (isi[produkDibuka.id]?.qty ?? 0) : 0}
        catatanSekarang={produkDibuka ? (isi[produkDibuka.id]?.note ?? '') : ''}
        onTutup={() => setProdukDibuka(null)}
        onSimpan={(n, note) => {
          if (produkDibuka) ubah(produkDibuka.id, n, note)
          setProdukDibuka(null)
        }}
      />

      {/* Shadow di sini FUNGSIONAL: memisahkan bar dari daftar yang lewat di bawahnya. */}
      {totalItem > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-2px_8px_rgba(0,0,0,0.06)]">
          <div className="mx-auto flex max-w-md items-center gap-3">
            <div className="min-w-0 flex-1">
              <p className="text-sm text-slate-600">{totalItem} item</p>
              <p className="text-base font-semibold">{rupiah(totalHarga)}</p>
            </div>
            {/* Link ke alamat sungguhan, bukan setTahap: inilah yang membuat
                refresh & tombol back HP berperilaku benar di layar berikutnya. */}
            <Link
              to="pesan"
              className="flex h-12 flex-1 items-center justify-center rounded-md bg-amber-700 text-base font-semibold text-white active:bg-amber-800"
            >
              Pesan sekarang
            </Link>
          </div>
        </div>
      )}
    </div>
  )
}
