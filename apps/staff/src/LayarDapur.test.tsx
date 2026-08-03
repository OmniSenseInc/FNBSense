// @vitest-environment jsdom
//
// jsdom dinyalakan PER BERKAS, sepola LayarAntrean.test.tsx: test fungsi murni
// tak butuh DOM, dan memaksa semuanya lewat jsdom cuma memperlambat.
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilRiwayat, tandaiSiap } from './api'
import LayarDapur from './LayarDapur'

// Seluruh modul api diganti: layar ini tak boleh menyentuh jaringan. Yang diuji
// adalah PENYAMBUNGANNYA — penyaringan dan pengurutannya sendiri sudah diuji
// sebagai fungsi murni di antrean.test.ts.
vi.mock('./api', () => ({
  ambilRiwayat: vi.fn(),
  tandaiSiap: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

/**
 * Pesanan lunas beberapa menit lalu.
 *
 * Jam bayarnya relatif terhadap waktu sungguhan, bukan tanggal beku: layar ini
 * menyaring "hari ini" lewat sedangDibuat() yang memanggil Date.now() sendiri,
 * dan tanggal mati akan membuat test ini membusuk esok hari.
 */
function lunas(id: string, nomor: string, note = '') {
  return {
    id,
    order_number: nomor,
    customer_name: 'Vincent',
    grand_total: 45_000,
    subtotal: 45_000,
    layanan: 0,
    pajak: 0,
    caraBayar: 'qris_static',
    niatBayar: null,
    meja: 'Meja 4',
    tipe: 'dine_in',
    waktuBayar: new Date(Date.now() - 5 * 60_000).toISOString(),
    klaimBayar: null,
    siapPada: null,
    created_at: new Date(Date.now() - 8 * 60_000).toISOString(),
    expires_at: null,
    items: [{ nama: 'Kopi Susu', qty: 2, hargaSatuan: 22_500, total: 45_000, note }],
  }
}

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarDapur onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
})

// Pembersihan EKSPLISIT — proyek ini tak memakai `globals: true`, jadi
// auto-cleanup @testing-library tak pernah tersambung. Lihat catatan yang sama
// di LayarAntrean.test.tsx.
afterEach(cleanup)

describe('layar dapur', () => {
  it('menampilkan isi pesanan berikut catatannya', async () => {
    // Catatan seperti "tanpa gula" tak punya tempat lain selama layar dapur
    // belum ada: kalau ia hilang di sini, ia hilang dari seluruh sistem.
    ;(ambilRiwayat as Mock).mockResolvedValue([lunas('ord-1', 'A-001', 'tanpa gula')])

    tampilkan()

    expect(await screen.findByText('A-001')).toBeTruthy()
    expect(screen.getByText(/Kopi Susu/)).toBeTruthy()
    expect(screen.getByText(/tanpa gula/)).toBeTruthy()
  })

  it('daftar kosong bilang tak ada yang menunggu, bukan layar putih', async () => {
    ;(ambilRiwayat as Mock).mockResolvedValue([])

    tampilkan()

    expect(await screen.findByText(/Tak ada pesanan yang menunggu dibuat/)).toBeTruthy()
  })
})

describe('tombol Siap diantar', () => {
  it('mengirim id pesanan yang ditekan', async () => {
    ;(ambilRiwayat as Mock).mockResolvedValue([lunas('ord-1', 'A-001')])
    ;(tandaiSiap as Mock).mockResolvedValue(lunas('ord-1', 'A-001'))

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Siap diantar' }))

    // Id-nya, bukan nomor pesanan: nomor dibangkitkan acak dan diulang saat
    // bentrok, jadi ia tak menunjuk satu pesanan.
    expect(tandaiSiap as Mock).toHaveBeenCalledWith('ord-1')
  })

  it('kartunya hilang begitu ditandai, tanpa menunggu polling berikutnya', async () => {
    // Kartu yang bertahan lima detik lagi mengundang sentuhan kedua — dan kasir
    // yang menekan dua kali akan mengira penandaannya gagal.
    ;(ambilRiwayat as Mock).mockResolvedValueOnce([lunas('ord-1', 'A-001')]).mockResolvedValue([])
    ;(tandaiSiap as Mock).mockResolvedValue(lunas('ord-1', 'A-001'))

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Siap diantar' }))

    expect(await screen.findByText(/Tak ada pesanan yang menunggu dibuat/)).toBeTruthy()
    expect(screen.queryByText('A-001')).toBeNull()
  })

  it('gagal menandai: kasir diberi tahu, bukan didiamkan', async () => {
    // Diam adalah kegagalan terburuk di sini: kasir menyangka pesanan sudah
    // diserahkan, pelanggan tak pernah dapat kabar, dan tak ada yang tahu.
    ;(ambilRiwayat as Mock).mockResolvedValue([lunas('ord-1', 'A-001')])
    ;(tandaiSiap as Mock).mockRejectedValue(new Error('Server sedang sibuk.'))

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Siap diantar' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByText('Server sedang sibuk.')).toBeTruthy()
  })

  it('sesi habis: dipulangkan ke login, tak menampilkan pesan galat', async () => {
    ;(ambilRiwayat as Mock).mockResolvedValue([lunas('ord-1', 'A-001')])
    ;(tandaiSiap as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()

    tampilkan(onKeluar)
    fireEvent.click(await screen.findByRole('button', { name: 'Siap diantar' }))

    // findBy* menunggu satu siklus mikrotask — cukup untuk penolakan yang
    // sudah terjadi merambat lewat catch.
    await screen.findByText('A-001')
    expect(onKeluar).toHaveBeenCalled()
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
