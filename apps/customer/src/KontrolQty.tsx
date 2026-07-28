import { MAKS_QTY } from './api'

/**
 * Kontrol jumlah: − / angka / +.
 *
 * Diangkat jadi komponen sendiri begitu dialog produk membutuhkannya juga.
 * Menyalinnya berarti dua tempat harus ingat menjepit di MAKS_QTY dan
 * menyaring karakter keyboard yang sama — dan yang lupa duluan pasti yang
 * jarang disentuh.
 *
 * Komponen ini tak menyimpan apa pun: nilainya milik pemanggil. Di kartu menu
 * itu keranjang, di dialog itu angka sementara yang belum disimpan.
 */
export default function KontrolQty({
  nilai,
  onUbah,
  label,
}: {
  nilai: number
  onUbah: (nilai: number) => void
  /** Nama produk — dipakai pembaca layar supaya tombolnya tak sekadar "tambah". */
  label: string
}) {
  const geser = (delta: number) =>
    onUbah(Math.min(MAKS_QTY, Math.max(0, nilai + delta)))

  /**
   * Qty diketik langsung. Dijepit di sini, BUKAN dibiarkan sampai server:
   * kalau pelanggan mengetik 500, server menolak dengan 422 setelah dia
   * susah payah mengisi keranjang. Lebih baik angkanya tak pernah bisa salah.
   */
  const ketik = (teks: string) => {
    // Keyboard HP masih bisa mengirim '-', '+', 'e', atau spasi walau numerik.
    const digit = teks.replace(/\D/g, '')
    onUbah(digit === '' ? 0 : Math.min(MAKS_QTY, Number(digit)))
  }

  return (
    <div className="flex items-center gap-1">
      <button
        type="button"
        onClick={() => geser(-1)}
        disabled={nilai === 0}
        aria-label={`Kurangi ${label}`}
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
        value={nilai === 0 ? '' : String(nilai)}
        onChange={(e) => ketik(e.target.value)}
        placeholder="0"
        aria-label={`Jumlah ${label}`}
        className="h-11 w-14 rounded-md border border-slate-300 text-center text-base font-semibold tabular-nums"
      />
      <button
        type="button"
        onClick={() => geser(1)}
        aria-label={`Tambah ${label}`}
        className="h-11 w-11 rounded-md border border-slate-300 text-xl leading-none"
      >
        +
      </button>
    </div>
  )
}
