import { useEffect, useRef, useState } from 'react'
import { MAKS_NOTE, type Produk } from './api'
import { labelTombol, rupiah } from './format'
import KontrolQty from './KontrolQty'

/**
 * Detail satu menu sebagai lembar yang naik dari bawah.
 *
 * Dibangun di atas <dialog> bawaan HTML, bukan <div> berlapis. Yang didapat
 * gratis dan berat kalau ditulis sendiri: latar gelap, tombol Escape,
 * fokus terkunci di dalam lembar (pembaca layar tak nyasar ke menu di
 * belakangnya), dan konten belakang otomatis non-interaktif.
 *
 * Yang BELUM ditangani, sadar: tombol back HP menutup seluruh halaman, bukan
 * lembar ini. Perbaikannya ikut saat rute dipecah — menambal riwayat browser
 * sekarang berarti menulisnya dua kali.
 */
export default function DialogProduk({
  produk,
  qtySekarang,
  catatanSekarang,
  onTutup,
  onSimpan,
}: {
  /** null = tertutup. Komponennya tetap ter-render supaya ref-nya stabil. */
  produk: Produk | null
  /** Jumlah yang sudah ada di keranjang — menentukan bunyi tombol. */
  qtySekarang: number
  /** Catatan yang sudah tersimpan untuk item ini, '' kalau belum ada. */
  catatanSekarang: string
  onTutup: () => void
  onSimpan: (qty: number, note: string) => void
}) {
  const ref = useRef<HTMLDialogElement>(null)

  // Angka & catatan sementara: keranjang baru berubah saat tombol bawah
  // ditekan, jadi pelanggan yang cuma mengintip lalu menutup tidak diam-diam
  // mengubah pesanannya.
  const [draft, setDraft] = useState(1)
  const [catatan, setCatatan] = useState('')

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return

    // showModal(), BUKAN atribut `open`. Dengan `open` lembarnya muncul tapi
    // tanpa latar gelap dan tanpa kunci fokus — kelihatan jalan, padahal
    // separuh mati.
    if (produk && !dialog.open) {
      setDraft(qtySekarang > 0 ? qtySekarang : 1)
      // Catatan lama ikut dimuat, bukan dikosongkan: pelanggan yang membuka
      // lagi untuk MENAMBAH jumlah tak boleh kehilangan "tanpa gula" yang
      // sudah dia tulis tadi.
      setCatatan(catatanSekarang)
      dialog.showModal()
    } else if (!produk && dialog.open) {
      dialog.close()
    }
  }, [produk, qtySekarang, catatanSekarang])

  const simpan = () => onSimpan(draft, catatan)

  // Tak ada yang bisa dihapus kalau item ini memang belum di keranjang.
  const tanpaEfek = draft === 0 && qtySekarang === 0

  return (
    <dialog
      ref={ref}
      // Escape dan close() bawaan sama-sama memancarkan ini — satu jalur
      // keluar, jadi state di induk tak pernah bertentangan dengan layar.
      onClose={onTutup}
      className="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-black/60"
    >
      {produk && (
        // Menutup dengan menyentuh area gelap di atas lembar. currentTarget
        // dipakai supaya sentuhan DI DALAM lembar tak ikut menutup.
        // Keyboard tak butuh ini: Escape sudah ditangani <dialog>.
        <div
          className="flex h-full w-full items-end justify-center"
          onClick={(e) => {
            if (e.target === e.currentTarget) onTutup()
          }}
        >
          <div className="relative flex max-h-[88svh] w-full max-w-md flex-col rounded-t-2xl bg-white">
            <button
              type="button"
              onClick={onTutup}
              aria-label="Tutup"
              className="absolute top-3 right-3 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-lg leading-none shadow-sm"
            >
              ✕
            </button>

            <div className="min-h-0 flex-1 overflow-y-auto">
              {/* Area gambar dilewati sama sekali kalau menunya tak berfoto —
                  kotak abu besar tak memberi informasi apa pun, cuma memaksa
                  pelanggan scroll melewatinya. */}
              {produk.gambarUrl && (
                <img
                  src={produk.gambarUrl}
                  alt=""
                  className="aspect-[4/3] w-full rounded-t-2xl object-cover"
                />
              )}

              <div className="px-4 pt-4 pb-2">
                <h2 className="text-xl font-semibold">{produk.nama}</h2>
                <p className="mt-1 text-lg font-semibold">{rupiah(produk.harga)}</p>
                {/* Deskripsi PENUH — inilah gunanya lembar ini ada. Di kartu
                    menu teksnya dipotong dua baris demi tinggi baris yang
                    seragam, jadi di sana pelanggan tak pernah bisa membacanya. */}
                {produk.deskripsi && (
                  <p className="mt-3 text-base whitespace-pre-line text-slate-600">
                    {produk.deskripsi}
                  </p>
                )}

                {/* Catatan tinggal di dalam area yang bisa di-scroll, bukan di
                    bar bawah: keyboard HP yang naik menutupi separuh layar,
                    dan kotak ketik yang menempel di bar akan tertimbun. */}
                <label className="mt-5 block">
                  <span className="text-sm font-semibold">Catatan (opsional)</span>
                  <textarea
                    value={catatan}
                    onChange={(e) => setCatatan(e.target.value)}
                    // maxLength = pagar pertama; susunPesanan memotong lagi
                    // karena isi keranjang bisa datang dari localStorage.
                    maxLength={MAKS_NOTE}
                    rows={2}
                    placeholder="Contoh: tanpa gula, jangan pedas"
                    className="mt-1.5 w-full resize-none rounded-md border border-slate-300 p-3 text-base placeholder:text-slate-400"
                  />
                  <span className="block text-sm text-slate-600">
                    Diteruskan ke dapur. Permintaan yang tak bisa dipenuhi akan
                    dikabari kasir.
                  </span>
                </label>
              </div>
            </div>

            <div className="border-t border-slate-200 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
              <div className="flex items-center justify-between">
                <KontrolQty nilai={draft} onUbah={setDraft} label={produk.nama} />
                {/* Subtotal satu item, bukan total pesanan: ini murni harga ×
                    jumlah, tak menjanjikan apa pun soal pajak & layanan yang
                    dihitung server nanti. */}
                <p className="text-base font-semibold tabular-nums">
                  {rupiah(produk.harga * draft)}
                </p>
              </div>

              <button
                type="button"
                onClick={simpan}
                disabled={tanpaEfek}
                className="mt-3 h-12 w-full rounded-md bg-amber-700 text-base font-semibold text-white active:bg-amber-800 disabled:opacity-40"
              >
                {labelTombol(draft, qtySekarang)}
              </button>
            </div>
          </div>
        </div>
      )}
    </dialog>
  )
}
