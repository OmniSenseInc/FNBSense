import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { ambilPesanan, klaimSudahBayar, type Pesanan } from './api'
import { rupiah } from './format'
import { TAMPILAN, jelaskanStatus } from './status'

/** Jeda polling. 5 detik: cukup cepat terasa hidup, cukup jarang untuk hemat baterai. */
const JEDA_MS = 5000

/** Satu baris rincian harga. Nilai negatif (promo) ditulis apa adanya. */
function Baris({ label, nilai }: { label: string; nilai: number }) {
  return (
    <div className="flex items-center justify-between py-1">
      <p className="text-sm text-slate-600">{label}</p>
      {/* Minus ditaruh di depan "Rp", bukan di depan angka: "Rp -5.000" terbaca
          seperti salah ketik, "−Rp 5.000" terbaca sebagai potongan. */}
      <p className="text-sm tabular-nums">
        {nilai < 0 ? `−${rupiah(-nilai)}` : rupiah(nilai)}
      </p>
    </div>
  )
}

/** Jam laporan, mis. "14:32". Waktu tak terbaca -> null, bukan tebakan. */
function jam(iso: string | null): string | null {
  if (!iso) return null

  const waktu = new Date(iso)
  if (Number.isNaN(waktu.getTime())) return null

  return waktu.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
}

/**
 * Garis kemajuan tiga titik: Dipesan -> Dibayar -> Siap diantar.
 *
 * Sengaja TIGA, bukan empat. "Sedang dibuat" bukan kejadian tersendiri
 * melainkan keadaan ANTARA dibayar dan siap — kalau dijadikan titik sendiri ia
 * menyala berbarengan dengan "Dibayar" dan pelanggan melihat dua titik aktif
 * tanpa tahu bedanya. Keterangannya sudah dipikul kartu status di atas.
 *
 * Tiap titik memakai jamnya sendiri dari server, bukan disimpulkan dari status:
 * jam yang null berarti belum terjadi, dan tak ada penanda kedua yang bisa
 * berselisih dengannya.
 */
function GarisKemajuan({ titik }: { titik: Array<{ label: string; waktu: string | null }> }) {
  return (
    <ol className="mt-6 flex items-start">
      {titik.map((t, i) => {
        const tercapai = t.waktu !== null

        return (
          <li key={t.label} className="flex flex-1 flex-col items-center text-center">
            <div className="flex w-full items-center">
              {/* Ruas kiri & kanan dibuat tak terlihat di ujung, bukan
                  dihilangkan: kalau elemennya dibuang, titik pertama dan
                  terakhir bergeser dan garisnya tak lagi lurus. */}
              <span
                className={`h-0.5 flex-1 ${i === 0 ? 'invisible' : tercapai ? 'bg-emerald-600' : 'bg-slate-200'}`}
              />
              <span
                aria-hidden="true"
                className={`h-3 w-3 shrink-0 rounded-full ${tercapai ? 'bg-emerald-600' : 'border-2 border-slate-300 bg-white'}`}
              />
              <span
                className={`h-0.5 flex-1 ${i === titik.length - 1 ? 'invisible' : titik[i + 1].waktu !== null ? 'bg-emerald-600' : 'bg-slate-200'}`}
              />
            </div>

            <p className={`mt-2 text-xs ${tercapai ? 'font-semibold' : 'text-slate-500'}`}>
              {t.label}
            </p>
            {/* Ruang jam dipesan walau kosong supaya tinggi ketiga kolom sama
                dan labelnya tak melompat saat titik berikutnya menyala. */}
            <p className="text-xs tabular-nums text-slate-500">{jam(t.waktu) ?? ' '}</p>
          </li>
        )
      })}
    </ol>
  )
}

export default function HalamanStatus() {
  const { id, qrToken } = useParams<{ id: string; qrToken: string }>()
  const [pesanan, setPesanan] = useState<Pesanan | null>(null)
  const [galat, setGalat] = useState(false)

  const [mengklaim, setMengklaim] = useState(false)
  const [galatKlaim, setGalatKlaim] = useState('')

  // Alamat gambar yang GAGAL dimuat, bukan sekadar penanda boolean. Bedanya
  // nyata: kalau owner memperbaiki QRIS-nya, alamatnya berubah, dan blok
  // pembayaran hidup lagi sendiri pada polling berikutnya — tanpa pelanggan
  // perlu me-refresh, dan tanpa effect tambahan untuk mengatur ulang.
  const [urlGagal, setUrlGagal] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return

    let batal = false
    let timer: number

    const periksa = async () => {
      try {
        const p = await ambilPesanan(id)
        if (batal) return
        setPesanan(p)
        setGalat(false)
        // Berhenti bertanya begitu tak ada lagi yang bisa berubah — polling
        // selamanya menghabiskan baterai pelanggan tanpa hasil.
        //
        // `paid` BUKAN akhir cerita: sesudah dibayar masih ada satu kabar yang
        // ditunggu, yaitu dapur menandai pesanan siap. Berhenti di `paid`
        // berarti garis kemajuan membeku di titik kedua dan pelanggan tak
        // pernah tahu pesanannya sudah bisa diambil kecuali me-refresh sendiri.
        const status = p.status.toLowerCase()
        if (status === 'pending' || (status === 'paid' && p.readyAt === null)) {
          timer = window.setTimeout(periksa, JEDA_MS)
        }
      } catch {
        if (batal) return
        setGalat(true)
        // Gagal sekali bukan berarti gagal selamanya (sinyal kafe naik-turun),
        // jadi tetap coba lagi — pelanggan tak perlu me-refresh manual.
        timer = window.setTimeout(periksa, JEDA_MS)
      }
    }

    periksa()

    return () => {
      batal = true
      window.clearTimeout(timer)
    }
  }, [id])

  const laporkanSudahBayar = async () => {
    if (!id || mengklaim) return

    setMengklaim(true)
    setGalatKlaim('')

    try {
      // Balasannya dipakai langsung: pelanggan yang baru menekan tombol harus
      // melihat tombolnya berubah SEKARANG, bukan setelah putaran polling
      // berikutnya — jeda diam lima detik terbaca sebagai "tidak tersimpan"
      // dan mengundang ketukan kedua.
      setPesanan(await klaimSudahBayar(id))
    } catch (e) {
      setGalatKlaim(e instanceof Error ? e.message : 'Gagal mengirim laporan.')
    } finally {
      setMengklaim(false)
    }
  }

  if (!pesanan) {
    return (
      <div className="mx-auto max-w-md px-4 py-16 text-center">
        <p className="text-base font-semibold">
          {galat ? 'Gagal memuat status' : 'Memuat status…'}
        </p>
        <p className="mt-1 text-sm text-slate-600">
          {galat ? 'Mencoba lagi otomatis. Kalau lama, tanya kasir.' : 'Sebentar ya.'}
        </p>
      </div>
    )
  }

  // Server sudah menyaring: qrisImageUrl hanya terisi untuk pesanan yang masih
  // menunggu bayar. Yang tersisa di sini cuma kasus gambarnya sendiri rusak.
  const qris = pesanan.qrisImageUrl !== urlGagal ? pesanan.qrisImageUrl : null
  const { judul, isi, rasa } = jelaskanStatus(
    pesanan.status,
    qris !== null,
    pesanan.readyAt !== null,
  )
  const tampilan = TAMPILAN[rasa]
  const jamKlaim = jam(pesanan.claimedAt)
  const selesai = rasa !== 'menunggu'

  return (
    <div className="min-h-svh bg-white text-slate-900">
      <main className="mx-auto max-w-md px-4 py-8">
        <p className="text-sm text-slate-600">Nomor pesanan</p>
        {/* Nomor dibuat besar: ini yang disebut kasir saat memanggil. */}
        <p className="text-2xl font-semibold tabular-nums">{pesanan.order_number}</p>

        {/* key={rasa}: animasi masuk diputar ulang setiap RASA-nya berubah,
            bukan tiap render. Tanpa key-nya, kartu yang berubah jadi hijau di
            tengah polling muncul diam-diam seperti tak terjadi apa-apa. */}
        <div
          key={rasa}
          className={`muncul mt-6 flex items-start gap-3 rounded-md border p-4 ${tampilan.kotak}`}
        >
          <span
            aria-hidden="true"
            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-lg leading-none ${tampilan.lingkaran}`}
          >
            {tampilan.ikon}
          </span>
          <div>
            <p className="text-base font-semibold">{judul}</p>
            <p className="mt-1 text-sm opacity-80">{isi}</p>
          </div>
        </div>

        {/* Disembunyikan untuk pesanan yang batal atau hangus: garis yang
            berhenti di tengah menyiratkan sesuatu masih berjalan, padahal
            perjalanannya sudah selesai dengan cara yang lain. */}
        {rasa !== 'gagal' && (
          <GarisKemajuan
            titik={[
              { label: 'Dipesan', waktu: pesanan.createdAt },
              { label: 'Dibayar', waktu: pesanan.paidAt },
              { label: 'Siap diantar', waktu: pesanan.readyAt },
            ]}
          />
        )}

        {/* Blok bayar ditaruh SEBELUM rincian: yang dicari pelanggan saat
            membuka halaman ini adalah cara membayar, dan kejutan angkanya
            sudah dijinakkan di layar ringkasan sebelumnya. */}
        {qris && (
          <div className="mt-6 flex flex-col items-center gap-3 rounded-md border border-slate-200 p-4 text-center">
            <div>
              <p className="text-sm text-slate-600">Bayar sejumlah</p>
              <p className="text-2xl font-semibold tabular-nums">
                {rupiah(pesanan.grand_total)}
              </p>
            </div>

            {/* Latar putih dipaksa, bukan diwarisi: pemindai butuh kontras
                gelap-di-terang, dan QRIS yang diunggah owner bisa saja PNG
                transparan yang jadi tak terbaca di atas latar apa pun. */}
            <div className="rounded-lg border border-slate-200 bg-white p-3">
              <img
                src={qris}
                alt="Kode QRIS untuk membayar pesanan ini"
                // Alamat rusak atau gambar hilang tak boleh meninggalkan ikon
                // patah di layar orang yang sedang membayar — seluruh bloknya
                // menghilang dan kalimat status kembali menyuruh ke kasir.
                onError={() => setUrlGagal(pesanan.qrisImageUrl)}
                className="block h-44 w-44 object-contain"
              />
            </div>

            {/* Langkah-langkahnya ditulis karena kamera HP tak bisa memotret
                layarnya sendiri. Tanpa petunjuk ini pelanggan menatap QR-nya
                lalu tetap berjalan ke kasir. */}
            <ol className="w-full list-inside list-decimal text-left text-sm text-slate-600">
              <li>Tekan dan tahan gambar di atas, lalu simpan</li>
              <li>Buka aplikasi bank atau e-wallet-mu</li>
              <li>Pilih Scan QR, lalu ambil dari galeri</li>
            </ol>

            {/* Tombol ini SINYAL, bukan syarat: kasir tetap bisa menerima
                pesanan yang tak pernah dilaporkan. Gunanya menaikkan pesanan
                ini ke pucuk antrean kasir dan menahan tenggatnya sekali,
                supaya uang yang sudah masuk tak kehilangan pesanannya. */}
            {jamKlaim ? (
              <div className="w-full rounded-md bg-slate-100 p-3 text-sm text-slate-600">
                Sudah dilaporkan pukul {jamKlaim}. Kasir sedang mengeceknya.
              </div>
            ) : (
              <button
                type="button"
                onClick={laporkanSudahBayar}
                disabled={mengklaim}
                className="w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"
              >
                {mengklaim ? 'Mengirim…' : 'Saya sudah bayar'}
              </button>
            )}

            {galatKlaim && <p className="text-sm text-rose-700">{galatKlaim}</p>}
          </div>
        )}

        {/* Rincian penuh, bukan cuma total: inilah tempat pelanggan melihat
            kenapa angkanya beda dari subtotal menu tadi. */}
        <div className="mt-6 border-t border-slate-200 pt-4">
          <Baris label="Subtotal menu" nilai={pesanan.gross_subtotal} />
          {pesanan.discount_total > 0 && (
            <Baris label="Promo" nilai={-pesanan.discount_total} />
          )}
          {/* Layanan & pajak SELALU ditampilkan, walau Rp 0. Pelanggan yang
              curiga ada biaya tersembunyi jadi punya jawaban tertulis, bukan
              cuma ketiadaan baris yang bisa berarti apa saja. */}
          <Baris label="Biaya layanan" nilai={pesanan.service_charge} />
          <Baris label="Pajak" nilai={pesanan.tax} />

          <div className="mt-3 flex items-center justify-between border-t border-slate-200 pt-3">
            <p className="text-base font-semibold">Total dibayar</p>
            <p className="text-[17px] font-semibold tabular-nums">
              {rupiah(pesanan.grand_total)}
            </p>
          </div>
        </div>

        {/* Jalan kembali ke menu HANYA setelah pesanannya berakhir. Menawarkannya
            selagi menunggu bayar membuat sebagian orang mengira pesanannya gagal
            lalu memesan ulang — antrean kasir kotor, dan ada risiko dia membayar
            dua kali untuk barang yang sama. */}
        {selesai && qrToken && (
          <Link
            to={`/t/${qrToken}`}
            className="mt-6 block rounded-md bg-slate-900 px-4 py-3 text-center text-sm font-semibold text-white"
          >
            {rasa === 'berhasil' ? 'Pesan lagi' : 'Pesan ulang'}
          </Link>
        )}

        <p className="mt-6 text-sm text-slate-600">
          Atas nama {pesanan.customer_name}. Simpan halaman ini sampai pesanan datang.
        </p>

        {galat && (
          <p className="mt-4 text-sm text-slate-600">
            Koneksi sempat terputus — status di atas mungkin belum yang terbaru.
          </p>
        )}
      </main>
    </div>
  )
}
