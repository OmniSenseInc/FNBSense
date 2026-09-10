import type { Pesanan } from './api'

/**
 * Logika murni layar "Pembatalan" — dipisah dari komponen supaya bisa diuji
 * tanpa merender apa pun, sepola antrean.ts di app kasir.
 */

/** Ringkasan satu pembatal: id pembatal, nama, berapa kali, berapa nominalnya. */
export type BarisPembatal = {
  id: string
  nama: string
  jumlah: number
  nominal: number
}

/** Nama pembatal: kasir yang dikenal, atau "Kasir (dihapus)" kalau tak ketemu. */
function namaPembatal(id: string, namaStaf: Map<string, string>): string {
  if (id === '?') return 'Tak diketahui'
  return namaStaf.get(id) ?? 'Kasir (dihapus)'
}

/**
 * Urutkan terbaru di atas. Server mengirim urut created_at, bukan jam batal/
 * kedaluwarsa — jadi diurutkan ulang memakai diubahPada (updated_at mutasi
 * terakhir), yang untuk pesanan batal == jam batal dan untuk pesanan kedaluwarsa
 * == jam kedaluwarsa.
 */
export function urutkanTerbaru(daftar: Pesanan[]): Pesanan[] {
  return [...daftar].sort((a, b) => (b.diubahPada ?? '').localeCompare(a.diubahPada ?? ''))
}

/**
 * Kelompokkan per pembatal: siapa yang paling sering membatalkan — inti
 * "detektif"-nya. Nominal terbesar di atas supaya yang paling layak dicurigai
 * terlihat duluan.
 */
export function kelompokkanPerPembatal(
  daftar: Pesanan[],
  namaStaf: Map<string, string>,
): BarisPembatal[] {
  const peta = new Map<string, BarisPembatal>()
  for (const p of daftar) {
    const id = p.pembatal ?? '?'
    const e = peta.get(id) ?? { id, nama: namaPembatal(id, namaStaf), jumlah: 0, nominal: 0 }
    e.jumlah += 1
    e.nominal += Number.isFinite(p.grand_total) ? p.grand_total : 0
    peta.set(id, e)
  }
  return [...peta.values()].sort((a, b) => b.nominal - a.nominal)
}
