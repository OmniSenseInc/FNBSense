import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { ambilPesanan, ambilSetelan, SESI_HABIS, type Pesanan, type Setelan } from './api'
import { jam, rupiah } from './format'
import { cetak, halangan, namaPrinter, sambung, tersambung } from './printerBluetooth'
import { barisStruk, keBytes } from './struk'

const NAMA_KAFE = import.meta.env.VITE_NAMA_KAFE ?? 'Nota Pembayaran'

/** Karakter per baris di kertas 58mm — sepasang dengan @page di bawah. */
const LEBAR_KERTAS = 32

/** Kosakata server -> bahasa yang dibaca orang di atas kertas. */
const LABEL_BAYAR: Record<string, string> = {
  cash: 'Tunai',
  qris_static: 'QRIS',
}

/**
 * Nota pembayaran yang siap dicetak.
 *
 * Pesanannya DIAMBIL SENDIRI dari id di alamat, bukan diterima sebagai prop
 * dari layar antrean. Itu yang membuat cetak ulang mungkin: nota yang cuma bisa
 * dirakit dari salinan hasil konfirmasi ikut mati begitu kasir meninggalkan
 * layarnya, dan pelanggan yang kembali setengah jam kemudian tak bisa dilayani.
 *
 * Mencetak lewat window.print() dan CSS, bukan lewat pustaka atau agen ESC/POS:
 * printer thermal muncul sebagai printer biasa di sistem operasi, jadi browser
 * sudah bisa mengirim ke sana tanpa satu pun dependensi baru.
 *
 * Semua angka diambil apa adanya dari jawaban server. Layar ini tak menjumlah
 * dan tak mengalikan apa pun — kertas yang dipegang pelanggan tak boleh bisa
 * berbeda dari yang tercatat di pembukuan.
 */
export default function LayarNota({ onKeluar }: { onKeluar: () => void }) {
  const { id } = useParams<{ id: string }>()
  const [pesanan, setPesanan] = useState<Pesanan | null>(null)
  const [galat, setGalat] = useState<string | null>(null)
  const [setelan, setSetelan] = useState<Setelan | null>(null)

  // Lewat ref supaya identitas fungsi dari App tak pernah memicu pengambilan
  // ulang yang tak perlu.
  const keluarRef = useRef(onKeluar)
  keluarRef.current = onKeluar

  useEffect(() => {
    if (!id) return

    let batal = false

    ambilPesanan(id)
      .then((hasil) => {
        if (batal) return
        setPesanan(hasil)
        setGalat(null)
      })
      .catch((err: unknown) => {
        if (batal) return
        // Sesi habis -> pulangkan ke login. Alamat nota tetap, jadi kasir
        // kembali ke nota yang sama begitu masuk lagi.
        if (err instanceof Error && err.message === SESI_HABIS) {
          keluarRef.current()
          return
        }
        setGalat(err instanceof Error ? err.message : 'Gagal memuat nota.')
      })

    return () => {
      batal = true
    }
  }, [id])

  // Identitas kafe untuk kepala struk. Kegagalannya SENGAJA ditelan: nota yang
  // sudah dimuat tak boleh hilang gara-gara nama kafenya tak terbaca, dan yang
  // hilang cuma tiga baris di kepala — bukan angkanya.
  useEffect(() => {
    let batal = false

    ambilSetelan()
      .then((hasil) => {
        if (!batal) setSetelan(hasil)
      })
      .catch(() => {})

    return () => {
      batal = true
    }
  }, [])

  if (pesanan === null) {
    return (
      <div className="min-h-svh bg-stone-100 text-stone-900">
        <Kepala sudahBayar={false} bisaCetak={false} />
        <p role={galat ? 'alert' : undefined} className="px-4 py-16 text-center text-sm">
          {galat ?? 'Memuat nota…'}
        </p>
      </div>
    )
  }

  return (
    <div className="min-h-svh bg-stone-100 text-stone-900">
      {/* Lebar kertas struk yang lazim. Margin nol supaya tak ada tepi kosong
          yang membuang kertas, dan tinggi 'auto' karena struk memanjang sesuai
          jumlah item, bukan sebaliknya. */}
      <style>{`
        @media print {
          @page { size: 58mm auto; margin: 0; }
          html, body { background: #fff; }
        }
      `}</style>

      <Kepala sudahBayar={pesanan.waktuBayar !== null} bisaCetak />

      <main className="mx-auto my-6 w-[58mm] bg-white p-3 text-[11px] leading-snug text-black print:my-0 print:w-full print:p-2">
        <div className="text-center">
          {/* Identitas dari setelan outlet, bukan dari env: satu build melayani
              banyak kafe, dan nama yang dipanggang saat build cuma benar untuk
              kafe pertama. Env tinggal jadi jaring saat owner belum mengisi. */}
          <p className="text-[13px] font-bold">{setelan?.namaOutlet ?? NAMA_KAFE}</p>
          {setelan?.alamatOutlet && <p>{setelan.alamatOutlet}</p>}
          {setelan?.teleponOutlet && <p className="tabular-nums">{setelan.teleponOutlet}</p>}
          <p className="mt-1 font-bold tabular-nums">{pesanan.order_number}</p>
          <p className="tabular-nums">{jam(pesanan.waktuBayar ?? pesanan.created_at)}</p>
        </div>

        <div className="mt-2 border-t border-dashed border-black pt-2">
          <p>Nama: {pesanan.customer_name}</p>
          {/* Meja ikut tercetak: pelanggan yang kembali membawa struk ini
              memberi kasir satu petunjuk lagi untuk menemukan pesanannya. */}
          {pesanan.meja && <p>Meja: {pesanan.meja}</p>}
          {/* Ditulis di nota, bukan cuma di layar: kalau kelak ada selisih kas,
              inilah satu-satunya bukti cetak cara bayar yang dicatat kasir. */}
          <p>
            Bayar:{' '}
            {pesanan.caraBayar ? (LABEL_BAYAR[pesanan.caraBayar] ?? pesanan.caraBayar) : '—'}
          </p>
        </div>

        <ul className="mt-2 border-t border-dashed border-black pt-2">
          {pesanan.items.map((item, i) => (
            // Kunci indeks: satu pesanan boleh memuat produk sama dua baris
            // dengan catatan berbeda, dan urutannya tak pernah berubah.
            <li key={i} className="mt-1 first:mt-0">
              <p>{item.nama}</p>
              <div className="flex justify-between tabular-nums">
                <span>
                  {item.qty} x {rupiah(item.hargaSatuan)}
                </span>
                <span>{rupiah(item.total)}</span>
              </div>
              {item.note && <p className="italic">- {item.note}</p>}
            </li>
          ))}
        </ul>

        <div className="mt-2 border-t border-dashed border-black pt-2">
          <Baris label="Subtotal" nilai={pesanan.grossSubtotal} />
          {/* Potongan promo jadi baris tersendiri: "Subtotal" di atas adalah
              angka KOTOR, dan baris ini yang menjelaskan selisihnya. Hilang
              saat tak ada promo — beda dari layanan/pajak yang wajib tampil. */}
          {/* Minus ditulis DI DEPAN "Rp", bukan lewat rupiah(-diskon): konsisten
              dengan struk cetak ("-Rp 10.000"), bukan "Rp -10.000" yang janggal. */}
          {pesanan.diskon > 0 && (
            <div className="flex justify-between tabular-nums">
              <span>{pesanan.promo ?? 'Diskon'}</span>
              <span>-{rupiah(pesanan.diskon)}</span>
            </div>
          )}
          {/* Pajak & layanan SELALU ditulis walau nol: pungutan wajib yang tak
              tercantum bikin orang mengira ada yang disembunyikan. */}
          <Baris label="Layanan" nilai={pesanan.layanan} />
          <Baris label="Pajak" nilai={pesanan.pajak} />
        </div>

        <div className="mt-2 flex justify-between border-t border-black pt-2 text-[13px] font-bold tabular-nums">
          <span>TOTAL</span>
          <span>{rupiah(pesanan.grand_total)}</span>
        </div>

        <p className="mt-3 text-center">Terima kasih</p>
      </main>

      <Termal pesanan={pesanan} setelan={setelan} />
    </div>
  )
}

/**
 * Cetak langsung ke printer termal Bluetooth.
 *
 * TAMBAHAN, bukan pengganti tombol Cetak di atas. Kalau printernya terdaftar
 * sebagai printer biasa di sistem, window.print() sudah cukup dan tak menuntut
 * izin apa pun. Jalur ini untuk yang lebih sering ditemui di lapangan: HP
 * Android dengan printer termal yang tak punya driver sama sekali.
 *
 * Blok ini menghilang HANYA kalau browsernya memang tak akan pernah bisa
 * (Safari, semua browser di iOS). Kalau browsernya bisa tapi halamannya belum
 * HTTPS, yang muncul adalah keterangannya — bukan kekosongan. Menghilang tanpa
 * jejak adalah kegagalan yang paling mahal di sini: fiturnya jalan mulus di
 * localhost lalu lenyap begitu dibuka dari alamat sungguhan, dan orang
 * menyimpulkan aplikasinya rusak alih-alih mencari sertifikatnya.
 */
function Termal({ pesanan, setelan }: { pesanan: Pesanan; setelan: Setelan | null }) {
  const [sibuk, setSibuk] = useState(false)
  const [galat, setGalat] = useState<string | null>(null)
  const [siap, setSiap] = useState(tersambung())

  const rintangan = halangan()

  if (rintangan === 'tidak-didukung') return null

  if (rintangan === 'butuh-https') {
    return (
      <p className="mx-auto w-[58mm] pb-8 text-center text-xs text-stone-500 print:hidden">
        Cetak langsung ke printer Bluetooth hanya bisa lewat alamat <b>https://</b>. Dari alamat ini,
        pakai tombol <b>Cetak</b> di atas.
      </p>
    )
  }

  async function jalankan(kerja: () => Promise<void>) {
    setSibuk(true)
    setGalat(null)

    try {
      await kerja()
      setSiap(tersambung())
    } catch (err: unknown) {
      // Pembatalan dialog pemilihan bukan kegagalan — kasir memang menutupnya.
      if (err instanceof Error && err.name === 'NotFoundError') {
        setGalat('Printer belum dipilih.')
      } else {
        setGalat(err instanceof Error ? err.message : 'Gagal mencetak.')
      }

      setSiap(tersambung())
    } finally {
      setSibuk(false)
    }
  }

  return (
    <section className="mx-auto w-[58mm] pb-8 print:hidden">
      {siap ? (
        <button
          type="button"
          disabled={sibuk}
          onClick={() =>
            jalankan(async () => {
              // Byte baru dirakit tiap kali dipencet, dan barisStruk melempar
              // kalau ada angka yang tak terbaca — jadi struk bertulis "Rp 0"
              // tak pernah sempat jadi byte.
              const baris = barisStruk(
                pesanan,
                {
                  nama: setelan?.namaOutlet ?? null,
                  alamat: setelan?.alamatOutlet ?? null,
                  telepon: setelan?.teleponOutlet ?? null,
                },
                LEBAR_KERTAS,
              )

              await cetak(keBytes(baris))
            })
          }
          className="w-full rounded-md bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
        >
          {sibuk ? 'Mencetak…' : `Cetak ke ${namaPrinter() ?? 'printer'}`}
        </button>
      ) : (
        <button
          type="button"
          disabled={sibuk}
          onClick={() => jalankan(() => sambung())}
          className="w-full rounded-md border border-stone-400 px-4 py-3 text-sm font-semibold disabled:opacity-50"
        >
          {sibuk ? 'Menyambungkan…' : 'Sambungkan printer termal'}
        </button>
      )}

      {galat && (
        <p role="alert" className="mt-2 text-center text-xs text-red-700">
          {galat}
        </p>
      )}

      {/* Daftar tersaring menyembunyikan printer yang UUID-nya tak kita kenal,
          dan printer yang tak muncul sama sekali adalah jalan buntu yang tak
          bisa diselesaikan kasir sendiri. */}
      {!siap && (
        <button
          type="button"
          disabled={sibuk}
          onClick={() => jalankan(() => sambung(true))}
          className="mt-2 w-full text-center text-xs text-stone-500 underline disabled:opacity-50"
        >
          Printer tidak muncul? Tampilkan semua perangkat
        </button>
      )}
    </section>
  )
}

/**
 * Bar atas. Tombol cetak disembunyikan selama notanya belum ada — menawarkan
 * cetak untuk layar kosong cuma menghasilkan kertas kosong.
 */
function Kepala({ sudahBayar, bisaCetak }: { sudahBayar: boolean; bisaCetak: boolean }) {
  // Nota yang baru dibuat (POS) belum tentu sudah dibayar — bukan bukti
  // pembayaran sampai `waktuBayar` terisi.
  return (
    <header className="flex items-center justify-between gap-3 border-b border-stone-200 bg-white px-4 py-3 print:hidden">
      {/* Link, bukan tombol history.back(): nota bisa dibuka langsung dari
          alamatnya (cetak ulang, tab baru), dan di situ tak ada halaman
          sebelumnya untuk dikembalikan. */}
      <Link to="/" className="rounded-md border border-stone-300 px-3 py-2 text-sm">
        ← Antrean
      </Link>
      <p className="text-sm text-stone-600">
        {sudahBayar ? 'Pembayaran tercatat' : 'Menunggu pembayaran'}
      </p>
      {bisaCetak ? (
        <button
          type="button"
          onClick={() => window.print()}
          className="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white"
        >
          Cetak
        </button>
      ) : (
        <span />
      )}
    </header>
  )
}

function Baris({ label, nilai }: { label: string; nilai: number }) {
  return (
    <div className="flex justify-between tabular-nums">
      <span>{label}</span>
      <span>{rupiah(nilai)}</span>
    </div>
  )
}
