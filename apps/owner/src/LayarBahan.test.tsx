// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilBahan, buatBahan, hapusBahan } from './api'
import LayarBahan from './LayarBahan'

vi.mock('./api', () => ({
  ambilBahan: vi.fn(),
  buatBahan: vi.fn(),
  hapusBahan: vi.fn(),
  ubahHargaBeli: vi.fn(),
  SATUAN: ['g', 'ml', 'pcs'],
  SESI_HABIS: 'SESI_HABIS',
}))

const bahan = (ubah = {}) => ({ id: 'b1', nama: 'Susu', satuan: 'ml', hargaBeli: 0, ...ubah })

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarBahan onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('menambah bahan', () => {
  it('mengirim nama dan satuan yang dipilih', async () => {
    ;(ambilBahan as Mock).mockResolvedValue([])
    ;(buatBahan as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Biji Kopi' } })
    fireEvent.change(screen.getByLabelText('Satuan'), { target: { value: 'g' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    await vi.waitFor(() => expect(buatBahan).toHaveBeenCalledWith('Biji Kopi', 'g', 0))
  })

  it('nama kosong ditolak tanpa menghubungi server', async () => {
    ;(ambilBahan as Mock).mockResolvedValue([])

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Tambah bahan' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(buatBahan).not.toHaveBeenCalled()
  })

  it('spasi di ujung dirapikan, bukan dikirim apa adanya', async () => {
    ;(ambilBahan as Mock).mockResolvedValue([])
    ;(buatBahan as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: '  Gula  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    await vi.waitFor(() => expect(buatBahan).toHaveBeenCalledWith('Gula', 'g', 0))
  })
})

describe('menghapus bahan', () => {
  it('butuh dua ketukan — resepnya ikut terhapus', async () => {
    ;(ambilBahan as Mock).mockResolvedValue([bahan()])
    ;(hapusBahan as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Hapus bahan Susu' }))

    // Ketukan pertama cuma membuka konfirmasi.
    expect(hapusBahan).not.toHaveBeenCalled()
    expect(screen.getByText(/Resepnya ikut terhapus/)).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Hapus Susu' }))
    await vi.waitFor(() => expect(hapusBahan).toHaveBeenCalledWith('b1'))
  })

  it('bisa dibatalkan', async () => {
    ;(ambilBahan as Mock).mockResolvedValue([bahan()])

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Hapus bahan Susu' }))
    fireEvent.click(screen.getByRole('button', { name: 'Batal' }))

    expect(hapusBahan).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Hapus bahan Susu' })).toBeTruthy()
  })
})

describe('daftar kosong', () => {
  it('menyebut akibatnya ke rantai stok, bukan cuma "kosong"', async () => {
    ;(ambilBahan as Mock).mockResolvedValue([])

    tampilkan()

    expect(await screen.findByText(/stok tak pernah diperiksa/)).toBeTruthy()
  })
})

describe('kegagalan', () => {
  it('sesi habis: dipulangkan ke login', async () => {
    ;(ambilBahan as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()

    tampilkan(onKeluar)

    await vi.waitFor(() => expect(onKeluar).toHaveBeenCalled())
  })
})
