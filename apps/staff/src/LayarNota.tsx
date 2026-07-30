import { type Pesanan } from './api'
import { jam, rupiah } from './format'

const NAMA_KAFE = import.meta.env.VITE_NAMA_KAFE ?? 'Nota Pembayaran'

/** Kosakata server -> bahasa yang dibaca orang di atas kertas. */
const LABEL_BAYAR: Record<string, string> = {
  cash: 'Tunai',
  qris_static: 'QRIS',
}

/**
 * Nota pembayaran yang siap dicetak.
 *
 * Mencetak lewat window.print() dan CSS, bukan lewat pustaka atau agen ESC/POS:
 * printer thermal muncul sebagai printer biasa di sistem operasi, jadi browser
 * sudah bisa mengirim ke sana tanpa satu pun dependensi baru. Cetak-langsung
 * tanpa dialog baru layak dibangun kalau dialognya benar-benar terasa
 * mengganggu setelah dipakai sungguhan.
 *
 * Semua angka diambil apa adanya dari jawaban server. Layar ini tak menjumlah
 * dan tak mengalikan apa pun — kertas yang dipegang pelanggan tak boleh bisa
 * berbeda dari yang tercatat di pembukuan.
 */
export default function LayarNota({
  pesanan,
  onKembali,
}: {
  pesanan: Pesanan
  onKembali: () => void
}) {
  return (
    <div className="min-h-svh bg-slate-100 text-slate-900">
      {/* Lebar kertas struk yang lazim. Margin nol supaya tak ada tepi kosong
          yang membuang kertas, dan tinggi 'auto' karena struk memanjang sesuai
          jumlah item, bukan sebaliknya. */}
      <style>{`
        @media print {
          @page { size: 58mm auto; margin: 0; }
          html, body { background: #fff; }
        }
      `}</style>

      <header className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 print:hidden">
        <button
          type="button"
          onClick={onKembali}
          className="rounded-md border border-slate-300 px-3 py-2 text-sm"
        >
          ← Antrean
        </button>
        <p className="text-sm text-slate-600">Pembayaran tercatat</p>
        <button
          type="button"
          onClick={() => window.print()}
          className="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white"
        >
          Cetak
        </button>
      </header>

      <main className="mx-auto my-6 w-[58mm] bg-white p-3 text-[11px] leading-snug text-black print:my-0 print:w-full print:p-2">
        <div className="text-center">
          <p className="text-[13px] font-bold">{NAMA_KAFE}</p>
          <p className="mt-1 font-bold tabular-nums">{pesanan.order_number}</p>
          <p className="tabular-nums">{jam(pesanan.waktuBayar ?? pesanan.created_at)}</p>
        </div>

        <div className="mt-2 border-t border-dashed border-black pt-2">
          <p>Nama: {pesanan.customer_name}</p>
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
          <Baris label="Subtotal" nilai={pesanan.subtotal} />
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
    </div>
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
