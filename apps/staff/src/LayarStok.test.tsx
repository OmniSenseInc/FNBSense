// @vitest-environment jsdom
//
// jsdom dinyalakan PER BERKAS, sepola LayarDapur.test.tsx.
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilStok } from './api'
import LayarStok from './LayarStok'

vi.mock('./api', () => ({
  ambilStok: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

function dasar() {
  return {
    id: 'aaaa1111-bbbb-2222-cccc-333344445555',
    nama: 'Susu Full Cream' as string | null,
    qty: 5000,
    minimum: 1000 as number | null,
    // Waktu relatif, bukan tanggal beku: tanggalJam() memformat apa adanya,
    // tapi tanggal mati membuat test ini membusuk sebagai dokumentasi.
    diperbarui: new Date(Date.now() - 60_000).toISOString(),
  }
}

function stok(ubah: Partial<ReturnType<typeof dasar>> = {}) {
  return { ...dasar(), ...ubah }
}

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarStok onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
})

// Pembersihan EKSPLISIT — proyek ini tak memakai `globals: true`.
afterEach(cleanup)

describe('layar stok', () => {
  it('menampilkan nama bahan dan saldonya', async () => {
    ;(ambilStok as Mock).mockResolvedValue([stok()])

    tampilkan()

    expect(await screen.findByText('Susu Full Cream')).toBeTruthy()
    expect(screen.getByText('5000')).toBeTruthy()
  })

  it('daftar kosong bilang belum ada bahan, bukan layar putih', async () => {
    ;(ambilStok as Mock).mockResolvedValue([])

    tampilkan()

    expect(await screen.findByText(/Belum ada bahan yang distok/)).toBeTruthy()
  })

  it('gagal memuat: kasir diberi tahu', async () => {
    ;(ambilStok as Mock).mockRejectedValue(new Error('Server sedang sibuk.'))

    tampilkan()

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByText('Server sedang sibuk.')).toBeTruthy()
  })

  it('sesi habis: dipulangkan ke login, tanpa pesan galat', async () => {
    ;(ambilStok as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()

    tampilkan(onKeluar)

    await vi.waitFor(() => expect(onKeluar).toHaveBeenCalled())
    expect(screen.queryByRole('alert')).toBeNull()
  })
})

describe('nama bahan hilang', () => {
  it('menampilkan potongan id, bukan kosong atau "null"', async () => {
    // Terjadi saat Catalog tak terjangkau: Inventory tetap membalas 200 dengan
    // ingredient_name null supaya angkanya tetap sampai.
    ;(ambilStok as Mock).mockResolvedValue([stok({ nama: null })])

    tampilkan()

    expect(await screen.findByText(/Bahan aaaa1111/)).toBeTruthy()
    expect(screen.queryByText(/null/)).toBeNull()
  })

  it('menjelaskan sekali di atas kenapa namanya tak ada', async () => {
    ;(ambilStok as Mock).mockResolvedValue([
      stok({ nama: null }),
      stok({ nama: null, id: 'bbbb2222-cccc-3333-dddd-444455556666' }),
    ])

    tampilkan()

    // Sekali, bukan sekali per baris — dua bahan tanpa nama tetap satu pesan.
    expect(await screen.findAllByText(/Sebagian nama bahan sedang tak bisa diambil/)).toHaveLength(1)
  })

  it('tak menakut-nakuti saat semua nama ada', async () => {
    ;(ambilStok as Mock).mockResolvedValue([stok()])

    tampilkan()

    await screen.findByText('Susu Full Cream')
    expect(screen.queryByText(/Sebagian nama bahan/)).toBeNull()
  })
})

describe('penanda menipis', () => {
  it('menyala saat saldo menyentuh batas', async () => {
    ;(ambilStok as Mock).mockResolvedValue([stok({ qty: 1000, minimum: 1000 })])

    tampilkan()

    expect(await screen.findByText(/Menipis/)).toBeTruthy()
  })

  it('TIDAK menyala untuk bahan tanpa batas walau saldonya nol', async () => {
    // minimum 0 berarti "tak diawasi". Tanpa penjaga ini, 0 <= 0 membuat setiap
    // bahan tanpa batas menyala oranye — peringatan yang menyala di mana-mana
    // sama saja dengan tak ada peringatan.
    ;(ambilStok as Mock).mockResolvedValue([stok({ qty: 0, minimum: 0 })])

    tampilkan()

    await screen.findByText('0')
    expect(screen.queryByText(/Menipis/)).toBeNull()
  })

  it('TIDAK menyala saat saldonya tak terbaca', async () => {
    // qty NaN = angka tak terbaca dari server. Menyebutnya "menipis" berarti
    // menyatakan sesuatu yang tak kita ketahui.
    ;(ambilStok as Mock).mockResolvedValue([stok({ qty: NaN, minimum: 1000 })])

    tampilkan()

    expect(await screen.findByText('—')).toBeTruthy()
    expect(screen.queryByText(/Menipis/)).toBeNull()
  })
})

describe('muat ulang', () => {
  it('menarik data lagi saat ditekan', async () => {
    ;(ambilStok as Mock)
      .mockResolvedValueOnce([stok({ qty: 5000 })])
      .mockResolvedValue([stok({ qty: 4200 })])

    tampilkan()
    await screen.findByText('5000')

    fireEvent.click(screen.getByRole('button', { name: 'Muat ulang' }))

    expect(await screen.findByText('4200')).toBeTruthy()
  })
})
