// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilPesanan, klaimSudahBayar, type Pesanan } from './api'
import HalamanStatus from './HalamanStatus'

/**
 * Mock PARSIAL: hanya dua pintu jaringan yang dipalsukan. `jelaskanStatus` dan
 * `TAMPILAN` di ./status sengaja dibiarkan asli — merekalah yang memutuskan
 * kalimat apa yang dibaca pelanggan, dan itu justru yang ingin dijaga.
 */
vi.mock('./api', async (asli) => ({
  ...(await asli<typeof import('./api')>()),
  ambilPesanan: vi.fn(),
  klaimSudahBayar: vi.fn(),
}))

const pesanan = (ubah: Partial<Pesanan> = {}): Pesanan => ({
  id: 'o-1',
  order_number: 'A-042',
  status: 'pending',
  customer_name: 'Vincent',
  gross_subtotal: 20000,
  discount_total: 0,
  service_charge: 0,
  tax: 0,
  grand_total: 20000,
  expires_at: null,
  qrisImageUrl: 'http://kafe.test/qris.png',
  claimedAt: null,
  items: [{ product_id: 'p1', product_name: 'Kopi Susu', qty: 1, line_total: 20000, note: '' }],
  createdAt: '2026-08-09T10:00:00+07:00',
  paidAt: null,
  readyAt: null,
  ...ubah,
})

function tampilkan() {
  render(
    <MemoryRouter initialEntries={['/t/qr-1/order/o-1']}>
      <Routes>
        <Route path="/t/:qrToken/order/:id" element={<HalamanStatus />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('blok pembayaran QRIS', () => {
  it('menampilkan QR dan jumlah yang harus dibayar', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan())

    tampilkan()

    expect(await screen.findByAltText('Kode QRIS untuk membayar pesanan ini')).toBeTruthy()
    // "Bayar sejumlah" khas blok ini; angkanya sendiri muncul dua kali karena
    // rincian di bawah ikut menyebutnya.
    expect(screen.getByText('Bayar sejumlah')).toBeTruthy()
    expect(screen.getAllByText('Rp 20.000').length).toBeGreaterThan(0)
  })

  /**
   * Server yang memutuskan QR boleh tampil atau tidak — ia mengirim null untuk
   * pesanan yang sudah dibayar, batal, atau hangus. Layar TIDAK menulis ulang
   * kondisi status itu, jadi null harus benar-benar menyembunyikan bloknya.
   */
  it('tanpa qrisImageUrl blok bayar tidak muncul sama sekali', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan({ qrisImageUrl: null }))

    tampilkan()
    await screen.findByText('A-042')

    expect(screen.queryByAltText('Kode QRIS untuk membayar pesanan ini')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Saya sudah bayar' })).toBeNull()
  })

  /**
   * Gambar yang gagal dimuat tak boleh meninggalkan ikon patah di layar orang
   * yang sedang membayar — seluruh bloknya menghilang.
   */
  it('gambar QRIS yang rusak menghilangkan bloknya, bukan menyisakan ikon patah', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan())

    tampilkan()
    const gambar = await screen.findByAltText('Kode QRIS untuk membayar pesanan ini')

    fireEvent.error(gambar)

    expect(screen.queryByAltText('Kode QRIS untuk membayar pesanan ini')).toBeNull()
  })

  it('nomor pesanan ditampilkan — ini yang disebut kasir saat memanggil', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan())

    tampilkan()

    expect(await screen.findByText('A-042')).toBeTruthy()
  })
})

describe('tombol "Saya sudah bayar"', () => {
  it('melaporkan pesanan yang benar ke server', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan())
    ;(klaimSudahBayar as Mock).mockResolvedValue(pesanan({ claimedAt: '2026-08-09T10:05:00+07:00' }))

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Saya sudah bayar' }))

    await vi.waitFor(() => expect(klaimSudahBayar).toHaveBeenCalledWith('o-1'))
  })

  /**
   * Sesudah melapor, tombolnya diganti keterangan berjam. Membiarkannya tetap
   * ada mengundang laporan kedua — yang ditolak server, dan penolakan itu
   * terbaca pelanggan sebagai "pembayaranku bermasalah".
   */
  it('setelah dilaporkan tombolnya berganti keterangan', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan())
    ;(klaimSudahBayar as Mock).mockResolvedValue(pesanan({ claimedAt: '2026-08-09T10:05:00+07:00' }))

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Saya sudah bayar' }))

    expect(await screen.findByText(/Sudah dilaporkan pukul/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Saya sudah bayar' })).toBeNull()
  })

  it('pesanan yang sudah pernah dilaporkan tak menawarkan tombol lagi', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(
      pesanan({ claimedAt: '2026-08-09T09:30:00+07:00' }),
    )

    tampilkan()
    await screen.findByText(/Sudah dilaporkan pukul/)

    expect(screen.queryByRole('button', { name: 'Saya sudah bayar' })).toBeNull()
  })

  it('penolakan server ditampilkan tanpa menghilangkan QR-nya', async () => {
    ;(ambilPesanan as Mock).mockResolvedValue(pesanan())
    ;(klaimSudahBayar as Mock).mockRejectedValue(new Error('Terlalu sering melapor.'))

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Saya sudah bayar' }))

    expect(await screen.findByText('Terlalu sering melapor.')).toBeTruthy()
    // QR wajib tetap ada: pelanggan yang gagal melapor masih harus bisa membayar.
    expect(screen.getByAltText('Kode QRIS untuk membayar pesanan ini')).toBeTruthy()
  })
})

describe('sebelum data tiba', () => {
  it('menampilkan keadaan memuat, bukan layar kosong', () => {
    ;(ambilPesanan as Mock).mockReturnValue(new Promise(() => {}))

    tampilkan()

    expect(screen.getByText(/Memuat status/)).toBeTruthy()
  })

  it('gagal memuat tetap menjanjikan percobaan ulang, bukan jalan buntu', async () => {
    ;(ambilPesanan as Mock).mockRejectedValue(new Error('jaringan'))

    tampilkan()

    expect(await screen.findByText(/Gagal memuat status/)).toBeTruthy()
    expect(screen.getByText(/Mencoba lagi otomatis/)).toBeTruthy()
  })
})
