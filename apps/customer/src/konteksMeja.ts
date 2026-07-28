import type { Dispatch, SetStateAction } from 'react'
import { useOutletContext } from 'react-router'
import type { Kategori, Meja } from './api'

/**
 * Apa yang dibagi rute induk /t/:qrToken ke halaman-halaman di bawahnya.
 *
 * Menu dan ringkasan memerlukan bahan yang sama persis: meja mana, menu apa,
 * dan isi keranjang. Dimuat sekali di induk, bukan sekali per halaman —
 * kalau tiap halaman mengambil sendiri, pindah menu <-> ringkasan berkedip
 * layar "Memuat menu…" padahal datanya baru saja ada di tangan.
 *
 * Dipisah dari LayoutMeja.tsx karena file komponen yang ikut mengekspor
 * fungsi biasa mematikan Fast Refresh untuk file itu.
 */
export type KonteksMeja = {
  qrToken: string
  meja: Meja
  kategori: Kategori[]
  qty: Record<string, number>
  setQty: Dispatch<SetStateAction<Record<string, number>>>
  /** Dipanggil HANYA setelah pesanan berhasil terkirim. */
  hapusKeranjang: () => void
}

export function useMeja() {
  return useOutletContext<KonteksMeja>()
}
