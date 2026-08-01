// @vitest-environment jsdom
//
// jsdom dinyalakan PER BERKAS, bukan lewat vite.config.ts: test fungsi murni
// (format, antrean, api) tak butuh DOM sama sekali, dan memaksa semuanya lewat
// jsdom cuma memperlambat berkas yang tak memerlukannya.
import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilAntrean } from './api'
import LayarAntrean from './LayarAntrean'

// Seluruh modul api diganti: layar ini tak boleh menyentuh jaringan, dan yang
// sedang diuji memang cuma cara ia MENYAJIKAN data yang sudah diterima.
vi.mock('./api', () => ({
  ambilAntrean: vi.fn(),
  batalkanPesanan: vi.fn(),
  konfirmasiBayar: vi.fn(),
  logout: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

/**
 * Satu pesanan dengan tenggat sekian menit dari sekarang.
 *
 * Tenggatnya dihitung relatif terhadap jam sungguhan alih-alih membekukan
 * waktu: komponennya memanggil Date.now() saat render, dan timer palsu
 * bertabrakan dengan polling yang juga memakai setTimeout.
 */
function pesanan(
  ubah: Partial<{
    id: string
    order_number: string
    grand_total: number
    sisaMenit: number | null
    meja: string | null
    tipe: string | null
    klaimBayar: string | null
  }> = {},
) {
  const sisa = ubah.sisaMenit === undefined ? 30 : ubah.sisaMenit

  return {
    id: ubah.id ?? 'ord-1',
    order_number: ubah.order_number ?? 'A-001',
    customer_name: 'Vincent',
    grand_total: ubah.grand_total ?? 45_000,
    subtotal: ubah.grand_total ?? 45_000,
    layanan: 0,
    pajak: 0,
    caraBayar: null,
    niatBayar: null,
    meja: ubah.meja ?? null,
    tipe: ubah.tipe ?? 'dine_in',
    waktuBayar: null,
    klaimBayar: ubah.klaimBayar ?? null,
    siapPada: null,
    created_at: new Date().toISOString(),
    expires_at: sisa === null ? null : new Date(Date.now() + sisa * 60_000 - 1_000).toISOString(),
    items: [{ nama: 'Kopi Susu', qty: 1, hargaSatuan: 45_000, total: 45_000, note: '' }],
  }
}

function tampilkan(daftar: ReturnType<typeof pesanan>[]) {
  ;(ambilAntrean as Mock).mockResolvedValue(daftar)

  return render(
    <MemoryRouter>
      <LayarAntrean onKeluar={() => {}} />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
})

// Pembersihan EKSPLISIT. Auto-cleanup @testing-library/react menyantol ke
// `afterEach` GLOBAL, dan proyek ini tak memakai `globals: true` — tiap test
// mengimpor describe/it/expect sendiri dari 'vitest'. Tanpa baris ini render
// dari test sebelumnya tetap menempel di dokumen, dan query berikutnya
// menemukan dua kartu bernomor sama.
afterEach(cleanup)

describe('klaim "sudah bayar" dari pelanggan', () => {
  it('muncul di kartu dengan jamnya', async () => {
    // Tanpa baris ini seluruh tombol di HP pelanggan tak berdampak apa pun:
    // kasir tak pernah tahu ada yang mengaku sudah transfer.
    tampilkan([pesanan({ klaimBayar: '2026-08-01T14:32:00+07:00' })])

    expect(await screen.findByText(/Pelanggan bilang sudah transfer/)).toBeTruthy()
  })

  it('kartu tanpa klaim tetap bersih', async () => {
    // Penanda yang muncul di semua kartu tak menunjuk apa pun.
    tampilkan([pesanan({})])

    await screen.findByText('A-001')
    expect(screen.queryByText(/Pelanggan bilang sudah transfer/)).toBeNull()
  })

  it('pengklaim naik ke pucuk antrean', async () => {
    tampilkan([
      pesanan({ id: 'a', order_number: 'A-001' }),
      pesanan({ id: 'b', order_number: 'B-002', klaimBayar: '2026-08-01T14:32:00+07:00' }),
    ])

    await screen.findByText('B-002')
    // Urutan DOM, bukan sekadar keberadaan: yang diuji justru posisinya.
    const nomor = screen.getAllByText(/^[AB]-00\d$/).map((el) => el.textContent)
    expect(nomor).toEqual(['B-002', 'A-001'])
  })
})

describe('sisa waktu bayar di kartu', () => {
  it('masih lama: ditulis biasa, tanpa desakan', async () => {
    tampilkan([pesanan({ sisaMenit: 12 })])

    expect(await screen.findByText(/Sisa 12 mnt untuk dibayar/)).toBeTruthy()
    expect(screen.queryByText(/segera cek/)).toBeNull()
  })

  it('lima menit ke bawah: berubah jadi desakan', async () => {
    // Ambangnya penentu apakah kasir sempat menengok HP sebelum pesanan disapu
    // orders:expire — pesanan yang uangnya mungkin sudah masuk.
    tampilkan([pesanan({ sisaMenit: 4 })])

    expect(await screen.findByText(/Sisa 4 mnt · segera cek/)).toBeTruthy()
  })

  it('sudah lewat: menyuruh memeriksa, TIDAK menyatakan hangus', async () => {
    // Server yang memutuskan hangus, bukan layar. Dan pesanan lewat-batas
    // justru yang paling mungkin sudah dibayar diam-diam lewat QRIS.
    tampilkan([pesanan({ sisaMenit: -3 })])

    expect(await screen.findByText(/Lewat batas bayar · segera cek/)).toBeTruthy()
  })

  it('tenggat tak dikirim server: tak menampilkan apa pun soal waktu', async () => {
    tampilkan([pesanan({ sisaMenit: null })])

    await screen.findByText('A-001')
    expect(screen.queryByText(/Sisa/)).toBeNull()
    expect(screen.queryByText(/Lewat batas/)).toBeNull()
  })

  it('tombol bayar TETAP ada walau waktunya habis', async () => {
    // Menonaktifkannya akan membalik risikonya: kasir tak bisa menerima uang
    // yang benar-benar sudah masuk.
    tampilkan([pesanan({ sisaMenit: -10 })])

    expect(await screen.findByRole('button', { name: 'Konfirmasi bayar' })).toBeTruthy()
  })
})

describe('penanda nominal kembar', () => {
  it('muncul di KEDUA kartu yang totalnya sama', async () => {
    tampilkan([
      pesanan({ id: 'a', order_number: 'A-001', grand_total: 45_000 }),
      pesanan({ id: 'b', order_number: 'A-002', grand_total: 45_000 }),
    ])

    expect(await screen.findAllByText(/Nominal sama dengan pesanan lain/)).toHaveLength(2)
  })

  it('tidak muncul saat semua total berbeda', async () => {
    tampilkan([
      pesanan({ id: 'a', order_number: 'A-001', grand_total: 45_000 }),
      pesanan({ id: 'b', order_number: 'A-002', grand_total: 22_000 }),
    ])

    await screen.findByText('A-001')
    expect(screen.queryByText(/Nominal sama dengan pesanan lain/)).toBeNull()
  })
})

describe('penanda meja', () => {
  it('menampilkan nama meja di kepala kartu', async () => {
    tampilkan([pesanan({ meja: 'Meja 4' })])

    expect(await screen.findByText(/Meja 4/)).toBeTruthy()
  })

  it('takeaway ditulis "Bawa pulang"', async () => {
    tampilkan([pesanan({ meja: null, tipe: 'takeaway' })])

    expect(await screen.findByText(/Bawa pulang/)).toBeTruthy()
  })

  it('dine-in tanpa meja tidak dikarang jadi "Bawa pulang"', async () => {
    tampilkan([pesanan({ meja: null, tipe: 'dine_in' })])

    await screen.findByText('A-001')
    expect(screen.queryByText(/Bawa pulang/)).toBeNull()
  })
})
