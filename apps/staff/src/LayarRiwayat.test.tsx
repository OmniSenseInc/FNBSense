// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilRiwayat } from './api'
import LayarRiwayat from './LayarRiwayat'

vi.mock('./api', () => ({
  ambilRiwayat: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

// riwayatHariIni() sengaja TIDAK di-mock: penyaringan "hari ini" dan urutannya
// adalah bagian dari yang membuat layar ini benar, jadi ia ikut diuji di sini
// bersama penyajiannya.
function lunas(id: string, nomor: string, menitLalu: number) {
  return {
    id,
    order_number: nomor,
    customer_name: 'Vincent',
    grand_total: 45_000,
    subtotal: 45_000,
    grossSubtotal: 45_000,
    diskon: 0,
    promo: null,
    layanan: 0,
    pajak: 0,
    caraBayar: 'qris_static',
    niatBayar: null,
    meja: 'Meja 1',
    tipe: 'dine_in',
    waktuBayar: new Date(Date.now() - menitLalu * 60_000).toISOString(),
    created_at: new Date(Date.now() - menitLalu * 60_000).toISOString(),
    expires_at: null,
    items: [],
  }
}

function tampilkan(daftar: ReturnType<typeof lunas>[]) {
  ;(ambilRiwayat as Mock).mockResolvedValue(daftar)

  return render(
    <MemoryRouter>
      <LayarRiwayat onKeluar={() => {}} />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
})

// Lihat catatan di LayarAntrean.test.tsx: tanpa `globals: true`, auto-cleanup
// @testing-library/react tak pernah terdaftar dan render lama menumpuk.
afterEach(cleanup)

describe('LayarRiwayat', () => {
  it('tiap baris menautkan ke nota pesanan itu', async () => {
    // Inilah seluruh alasan layar ini ada. Tautan yang menunjuk alamat salah
    // tetap terlihat normal sampai ada yang menekannya di depan pelanggan.
    tampilkan([lunas('ord-9', 'A-009', 5)])

    const tautan = await screen.findByRole('link', { name: /A-009/ })

    expect(tautan.getAttribute('href')).toBe('/nota/ord-9')
  })

  it('yang paling baru dibayar berada di baris pertama', async () => {
    tampilkan([lunas('lama', 'A-001', 300), lunas('baru', 'A-002', 5)])

    const tautan = await screen.findAllByRole('link', { name: /A-00/ })

    expect(tautan[0].getAttribute('href')).toBe('/nota/baru')
  })

  it('hari yang masih kosong dijelaskan, bukan dibiarkan jadi layar kosong', async () => {
    tampilkan([])

    expect(await screen.findByText(/Belum ada pesanan yang dibayar hari ini/)).toBeTruthy()
  })
})
