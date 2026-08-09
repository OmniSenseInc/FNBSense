// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import type { Keranjang, Produk } from './api'
import HalamanMenu from './HalamanMenu'
import { useMeja } from './konteksMeja'

/**
 * Hanya konteks rute yang dipalsukan. `./api` sengaja TIDAK di-mock: halaman ini
 * tak menyentuh jaringan sama sekali — meja, menu, dan keranjang semuanya datang
 * dari rute induk. Mock yang lebih lebar cuma akan menyembunyikan kalau suatu
 * saat halaman ini diam-diam mulai mengambil sendiri.
 */
vi.mock('./konteksMeja', () => ({ useMeja: vi.fn() }))

const produk = (ubah: Partial<Produk> = {}): Produk => ({
  id: 'p1',
  nama: 'Espresso',
  deskripsi: 'Pahit, pekat',
  harga: 18000,
  gambarUrl: null,
  habis: false,
  ...ubah,
})

function tampilkan(daftar: Produk[], isi: Keranjang = {}) {
  const setIsi = vi.fn()
  ;(useMeja as Mock).mockReturnValue({
    qrToken: 'qr-1',
    meja: { tenant_id: 't1', outlet_id: 'o1', table_id: 'm1', label: 'Meja 4' },
    kategori: [{ id: 'k1', nama: 'Kopi', produk: daftar }],
    isi,
    setIsi,
    hapusKeranjang: vi.fn(),
  })

  render(
    <MemoryRouter>
      <HalamanMenu />
    </MemoryRouter>,
  )

  return { setIsi }
}

/** Jalankan updater yang dikirim ke setIsi, seperti React akan melakukannya. */
function keranjangSetelah(setIsi: Mock, awal: Keranjang): Keranjang {
  const updater = setIsi.mock.calls[0][0] as (lama: Keranjang) => Keranjang

  return updater(awal)
}

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('penanda habis', () => {
  it('produk habis tetap tampil, tidak lenyap dari menu', () => {
    tampilkan([produk({ habis: true })])

    // Inilah keputusan yang dijaga: pelanggan yang melihat "Espresso — Habis"
    // tahu kafe ini punya Espresso dan kembali besok. Produk yang lenyap tak
    // pernah masuk ingatannya.
    expect(screen.getByText('Espresso')).toBeTruthy()
  })

  it('diberi label kata, bukan cuma diredupkan', () => {
    tampilkan([produk({ habis: true })])

    // Warna abu saja tak terbaca oleh pelanggan yang buta warna atau sedang
    // di bawah matahari.
    expect(screen.getByText('Habis hari ini')).toBeTruthy()
  })

  it('produk tersedia tidak ikut berlabel', () => {
    tampilkan([produk()])

    expect(screen.queryByText('Habis hari ini')).toBeNull()
  })

  it('tombol detail produk habis dimatikan', () => {
    tampilkan([produk({ habis: true })])

    const tombol = screen.getByRole('button', { name: /Espresso/ })
    expect((tombol as HTMLButtonElement).disabled).toBe(true)
  })

  it('produk habis kehilangan kontrol tambah', () => {
    tampilkan([produk({ habis: true })])

    // KontrolQty memberi tombol tambah; kalau ia masih ada, pelanggan bisa
    // menyusun keranjang yang sudah pasti ditolak server.
    expect(screen.queryByRole('button', { name: /Tambah/ })).toBeNull()
  })

  it('produk tersedia tetap punya kontrol tambah', () => {
    tampilkan([produk()])

    expect(screen.getByRole('button', { name: /Tambah/ })).toBeTruthy()
  })
})

describe('produk habis yang terlanjur di keranjang', () => {
  const isiAwal: Keranjang = { p1: { qty: 2, note: '' } }

  /**
   * Stok bisa habis SEMENTARA pelanggan memilih. Menghilangkan kontrolnya
   * begitu saja mengunci barang yang tak bisa dibayar di dalam keranjang, dan
   * pesanannya ditolak server tanpa pelanggan punya cara membatalkan dari sini.
   */
  it('diberi tombol hapus, bukan dibiarkan terkunci', () => {
    tampilkan([produk({ habis: true })], isiAwal)

    expect(screen.getByRole('button', { name: 'Hapus dari keranjang' })).toBeTruthy()
  })

  it('tombol hapus benar-benar mengeluarkannya dari keranjang', () => {
    const { setIsi } = tampilkan([produk({ habis: true })], isiAwal)

    fireEvent.click(screen.getByRole('button', { name: 'Hapus dari keranjang' }))

    expect(keranjangSetelah(setIsi, isiAwal).p1).toBeUndefined()
  })

  it('produk habis yang TIDAK di keranjang tak diberi tombol hapus', () => {
    tampilkan([produk({ habis: true })], {})

    expect(screen.queryByRole('button', { name: 'Hapus dari keranjang' })).toBeNull()
  })
})

describe('pencarian', () => {
  it('produk habis tetap ikut tersaring, bukan diperlakukan khusus', () => {
    tampilkan([produk({ habis: true }), produk({ id: 'p2', nama: 'Latte' })])

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'latte' } })

    expect(screen.queryByText('Espresso')).toBeNull()
    expect(screen.getByText('Latte')).toBeTruthy()
  })
})
