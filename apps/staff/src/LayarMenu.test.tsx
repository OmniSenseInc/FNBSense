// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilKategori, ambilProduk, buatKategori, buatProduk, ubahProduk } from './api'
import LayarMenu from './LayarMenu'

vi.mock('./api', () => ({
  ambilKategori: vi.fn(),
  ambilProduk: vi.fn(),
  buatKategori: vi.fn(),
  buatProduk: vi.fn(),
  ubahProduk: vi.fn(),
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

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarMenu onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

function siapkan(k: unknown[] = [kategori()], p: unknown[] = [produk()]) {
  ;(ambilKategori as Mock).mockResolvedValue(k)
  ;(ambilProduk as Mock).mockResolvedValue(p)
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
