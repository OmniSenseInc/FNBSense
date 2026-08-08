// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import {
  ambilBahan,
  ambilKategori,
  ambilProduk,
  ambilResep,
  buatKategori,
  buatProduk,
  buatResep,
  ubahProduk,
} from './api'
import LayarMenu from './LayarMenu'

vi.mock('./api', () => ({
  ambilBahan: vi.fn(),
  ambilKategori: vi.fn(),
  ambilProduk: vi.fn(),
  ambilResep: vi.fn(),
  buatKategori: vi.fn(),
  buatProduk: vi.fn(),
  buatResep: vi.fn(),
  hapusResep: vi.fn(),
  ubahProduk: vi.fn(),
  ubahTakaran: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

const kategori = (ubah = {}) => ({ id: 'k1', nama: 'Kopi', aktif: true, ...ubah })
const produk = (ubah = {}) => ({
  id: 'p1',
  nama: 'Kopi Susu',
  harga: 25000,
  kategoriId: 'k1' as string | null,
  tersedia: true,
  ...ubah,
})
const bahan = (ubah = {}) => ({ id: 'b1', nama: 'Susu', satuan: 'ml', ...ubah })
const resep = (ubah = {}) => ({ id: 'r1', produkId: 'p1', bahanId: 'b1', takaran: 150, ...ubah })

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarMenu onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

function siapkan(
  k: unknown[] = [kategori()],
  p: unknown[] = [produk()],
  b: unknown[] = [bahan()],
  r: unknown[] = [resep()],
) {
  ;(ambilKategori as Mock).mockResolvedValue(k)
  ;(ambilProduk as Mock).mockResolvedValue(p)
  ;(ambilBahan as Mock).mockResolvedValue(b)
  ;(ambilResep as Mock).mockResolvedValue(r)
}

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('kategori wajib', () => {
  it('menolak produk tanpa kategori SEBELUM menghubungi server', async () => {
    // Server masih menerima category_id null, dan produk yang lahir begitu tak
    // pernah muncul di menu pelanggan tanpa satu pun pesan. Layar ini pagar
    // pertamanya.
    siapkan()

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Teh' } })
    fireEvent.change(screen.getByLabelText('Harga'), { target: { value: '15000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah produk' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(buatProduk).not.toHaveBeenCalled()
  })

  it('mengirim kategori yang dipilih', async () => {
    siapkan()
    ;(buatProduk as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Teh' } })
    fireEvent.change(screen.getByLabelText('Harga'), { target: { value: '15000' } })
    fireEvent.change(screen.getByLabelText('Kategori'), { target: { value: 'k1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah produk' }))

    await vi.waitFor(() => expect(buatProduk).toHaveBeenCalledWith('Teh', 15000, 'k1'))
  })

  it('tanpa kategori sama sekali, tombol tambah produk mati dan alasannya disebut', async () => {
    siapkan([], [])

    tampilkan()

    expect(await screen.findByText(/Belum ada kategori/)).toBeTruthy()
    expect(
      (screen.getByRole('button', { name: 'Tambah produk' }) as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('menolak harga dengan pemisah ribuan', async () => {
    siapkan()

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Teh' } })
    fireEvent.change(screen.getByLabelText('Harga'), { target: { value: '15.000' } })
    fireEvent.change(screen.getByLabelText('Kategori'), { target: { value: 'k1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah produk' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(buatProduk).not.toHaveBeenCalled()
  })
})

describe('produk yang terlanjur tanpa kategori', () => {
  it('ditandai di barisnya sendiri, bukan sebagai peringatan umum', async () => {
    siapkan([kategori()], [produk({ kategoriId: null })])

    tampilkan()

    expect(await screen.findByText(/Tidak muncul di menu pelanggan/)).toBeTruthy()
  })

  it('bisa diperbaiki dari situ juga', async () => {
    siapkan([kategori()], [produk({ kategoriId: null })])
    ;(ubahProduk as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Kategori untuk Kopi Susu'), {
      target: { value: 'k1' },
    })

    await vi.waitFor(() => expect(ubahProduk).toHaveBeenCalledWith('p1', { kategoriId: 'k1' }))
  })
})

describe('kategori nonaktif', () => {
  it('disebut menyembunyikan isinya — supaya owner tak mencari di produk', async () => {
    siapkan([kategori({ aktif: false })])

    tampilkan()

    expect(await screen.findByText(/isinya tak tampil/)).toBeTruthy()
  })
})

describe('ketersediaan produk', () => {
  it('menyembunyikan produk dari pelanggan lewat satu tombol', async () => {
    siapkan()
    ;(ubahProduk as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Habiskan (sembunyikan)' }))

    await vi.waitFor(() => expect(ubahProduk).toHaveBeenCalledWith('p1', { tersedia: false }))
  })
})

describe('resep', () => {
  it('produk tanpa resep ditandai — itu yang membuatnya lolos gerbang stok', async () => {
    siapkan([kategori()], [produk()], [bahan()], [])

    tampilkan()

    expect(await screen.findByText(/stoknya tak pernah diperiksa/)).toBeTruthy()
  })

  it('produk yang punya resep tidak ditandai', async () => {
    siapkan()

    tampilkan()

    await screen.findByText('Kopi Susu')
    expect(screen.queryByText(/stoknya tak pernah diperiksa/)).toBeNull()
  })

  it('panel tertutup sampai diketuk', async () => {
    siapkan()

    tampilkan()

    await screen.findByText('Kopi Susu')
    expect(screen.queryByText('Resep Kopi Susu')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Resep Kopi Susu' }))
    expect(screen.getByText('Resep Kopi Susu')).toBeTruthy()
  })

  it('menambah bahan mengirim id produk yang panelnya terbuka', async () => {
    siapkan([kategori()], [produk()], [bahan(), bahan({ id: 'b2', nama: 'Biji Kopi', satuan: 'g' })])
    ;(buatResep as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Resep Kopi Susu' }))
    fireEvent.change(screen.getByLabelText('Bahan untuk Kopi Susu'), { target: { value: 'b2' } })
    fireEvent.change(screen.getByLabelText('Takaran untuk Kopi Susu'), { target: { value: '18' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    await vi.waitFor(() => expect(buatResep).toHaveBeenCalledWith('p1', 'b2', 18))
  })
})

describe('kegagalan', () => {
  it('kategori kosong ditolak tanpa memanggil server', async () => {
    siapkan()

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Tambah kategori' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(buatKategori).not.toHaveBeenCalled()
  })

  it('sesi habis: dipulangkan ke login', async () => {
    ;(ambilKategori as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    ;(ambilProduk as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()

    tampilkan(onKeluar)

    await vi.waitFor(() => expect(onKeluar).toHaveBeenCalled())
  })
})
