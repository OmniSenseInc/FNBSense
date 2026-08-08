// @vitest-environment jsdom
//
// jsdom dinyalakan PER BERKAS, sepola LayarStok.test.tsx.
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilShiftBerjalan, bukaShift, tutupShift } from './api'
import LayarShift from './LayarShift'

vi.mock('./api', () => ({
  ambilShiftBerjalan: vi.fn(),
  bukaShift: vi.fn(),
  tutupShift: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

function laporan(ubah: Record<string, unknown> = {}) {
  return {
    totalPenjualan: 120000,
    transaksi: 3,
    tunai: 80000,
    qris: 40000,
    modalAwal: 100000,
    kasSeharusnya: 180000,
    kasDihitung: null as number | null,
    selisih: null as number | null,
    ...ubah,
  }
}

function shift(ubah: Record<string, unknown> = {}) {
  return {
    id: 'aaaa1111-bbbb-2222-cccc-333344445555',
    status: 'open',
    modalAwal: 100000,
    dibukaPada: new Date(Date.now() - 3_600_000).toISOString(),
    ditutupPada: null as string | null,
    laporan: laporan(),
    ...ubah,
  }
}

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarShift onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
})

afterEach(cleanup)

describe('tak ada shift berjalan', () => {
  it('menawarkan buka shift, bukan layar kosong', async () => {
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(null)

    tampilkan()

    expect(await screen.findByRole('heading', { name: 'Buka shift' })).toBeTruthy()
    expect(screen.getByLabelText('Modal awal di laci')).toBeTruthy()
  })

  it('menolak modal awal yang bukan rupiah bulat SEBELUM menghubungi server', async () => {
    // 50.000 dengan titik adalah cara orang Indonesia menulis uang, dan Number()
    // membacanya sebagai 50 — angka yang diterima server tanpa protes. Yang
    // dijaga di sini bukan format, tapi modal awal yang salah seribu kali lipat.
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(null)

    tampilkan()
    // Peran heading, bukan teks: "Buka shift" adalah judul DAN tombol.
    await screen.findByRole('heading', { name: 'Buka shift' })

    fireEvent.change(screen.getByLabelText('Modal awal di laci'), {
      target: { value: '50.000' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Buka shift' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(bukaShift).not.toHaveBeenCalled()
  })

  it('modal awal nol diterima — laci memang boleh mulai kosong', async () => {
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(null)
    ;(bukaShift as Mock).mockResolvedValue(shift({ modalAwal: 0 }))

    tampilkan()
    // Peran heading, bukan teks: "Buka shift" adalah judul DAN tombol.
    await screen.findByRole('heading', { name: 'Buka shift' })

    fireEvent.change(screen.getByLabelText('Modal awal di laci'), { target: { value: '0' } })
    fireEvent.click(screen.getByRole('button', { name: 'Buka shift' }))

    await vi.waitFor(() => expect(bukaShift).toHaveBeenCalledWith(0))
  })
})

describe('shift berjalan', () => {
  it('menampilkan X-report dan tombol tutup', async () => {
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(shift())

    tampilkan()

    expect(await screen.findByText('Rp 120.000')).toBeTruthy()
    expect(screen.getByText('Rp 180.000')).toBeTruthy() // kas seharusnya
    expect(screen.getByRole('button', { name: 'Tutup shift' })).toBeTruthy()
    // Belum ditutup: tak ada kas dihitung, tak ada selisih.
    expect(screen.queryByText('Kas dihitung')).toBeNull()
    expect(screen.queryByText('Selisih')).toBeNull()
  })

  it('QRIS ditampilkan tapi ditandai tidak masuk laci', async () => {
    // Kalau QRIS ikut ke "kas seharusnya", selisihnya selalu minus sebesar
    // penjualan non-tunai dan kasir dituduh kehilangan uang tiap hari.
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(shift())

    tampilkan()

    expect(await screen.findByText('QRIS (tidak masuk laci)')).toBeTruthy()
  })

  it('menutup shift lalu menampilkan selisihnya', async () => {
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(shift())
    ;(tutupShift as Mock).mockResolvedValue(
      shift({
        status: 'closed',
        ditutupPada: new Date().toISOString(),
        laporan: laporan({ kasDihitung: 175000, selisih: -5000 }),
      }),
    )

    tampilkan()
    await screen.findByRole('button', { name: 'Tutup shift' })

    fireEvent.change(screen.getByLabelText('Uang tunai yang dihitung di laci'), {
      target: { value: '175000' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Tutup shift' }))

    expect(await screen.findByText('Shift ditutup')).toBeTruthy()
    expect(screen.getByText('Rp -5.000')).toBeTruthy()
    await vi.waitFor(() =>
      expect(tutupShift).toHaveBeenCalledWith('aaaa1111-bbbb-2222-cccc-333344445555', 175000),
    )
  })

  it('sesudah ditutup, tombol tutup hilang dan buka shift muncul lagi', async () => {
    // Tanpa ini layar menawarkan menutup shift yang sudah tertutup — server
    // membalas 409 dan kasir mengira sistemnya rusak.
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(shift())
    ;(tutupShift as Mock).mockResolvedValue(
      shift({ status: 'closed', laporan: laporan({ kasDihitung: 180000, selisih: 0 }) }),
    )

    tampilkan()
    await screen.findByRole('button', { name: 'Tutup shift' })

    fireEvent.change(screen.getByLabelText('Uang tunai yang dihitung di laci'), {
      target: { value: '180000' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Tutup shift' }))

    await screen.findByText('Shift ditutup')
    expect(screen.queryByRole('button', { name: 'Tutup shift' })).toBeNull()
    expect(screen.getByRole('button', { name: 'Buka shift' })).toBeTruthy()
  })
})

describe('kegagalan', () => {
  it('bentrok dari server ditampilkan apa adanya, bukan diterjemahkan jadi soal pesanan', async () => {
    // 409 di sini berarti "sudah ada shift terbuka". Pesan lama di mintaJson
    // berbunyi soal antrean pesanan — kalimat yang tak menjelaskan apa pun.
    ;(ambilShiftBerjalan as Mock).mockResolvedValue(null)
    ;(bukaShift as Mock).mockRejectedValue(new Error('Sudah ada shift terbuka untuk outlet ini.'))

    tampilkan()
    // Peran heading, bukan teks: "Buka shift" adalah judul DAN tombol.
    await screen.findByRole('heading', { name: 'Buka shift' })

    fireEvent.change(screen.getByLabelText('Modal awal di laci'), { target: { value: '200000' } })
    fireEvent.click(screen.getByRole('button', { name: 'Buka shift' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByText('Sudah ada shift terbuka untuk outlet ini.')).toBeTruthy()
  })

  it('sesi habis: dipulangkan ke login, tanpa pesan galat', async () => {
    ;(ambilShiftBerjalan as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()

    tampilkan(onKeluar)

    await vi.waitFor(() => expect(onKeluar).toHaveBeenCalled())
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
