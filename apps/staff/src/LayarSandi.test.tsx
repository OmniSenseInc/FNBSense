// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { gantiSandi } from './api'
import LayarSandi from './LayarSandi'

vi.mock('./api', () => ({
  gantiSandi: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

function tampilkan(onKeluar: () => void = () => {}) {
  render(
    <MemoryRouter>
      <LayarSandi onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

function isi(lama: string, baru: string, ulangi = baru) {
  fireEvent.change(screen.getByLabelText('Sandi sekarang'), { target: { value: lama } })
  fireEvent.change(screen.getByLabelText('Sandi baru'), { target: { value: baru } })
  fireEvent.change(screen.getByLabelText('Ketik ulang sandi baru'), { target: { value: ulangi } })
}

const kirim = () => fireEvent.click(screen.getByRole('button', { name: 'Ganti sandi' }))

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('ganti sandi', () => {
  it('mengirim sandi lama dan sandi baru', async () => {
    ;(gantiSandi as Mock).mockResolvedValue(undefined)

    tampilkan()
    isi('Lama12345', 'Baru67890')
    kirim()

    await vi.waitFor(() => expect(gantiSandi).toHaveBeenCalledWith('Lama12345', 'Baru67890'))
  })

  it('isian kosong ditolak tanpa menghubungi server', async () => {
    tampilkan()
    kirim()

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(gantiSandi).not.toHaveBeenCalled()
  })

  /**
   * Ketikan ulang yang tak sama dihentikan DI SINI, bukan dibiarkan jadi 422.
   * Kalau menunggu server, orangnya sudah menekan tombol dan isian sudah
   * terlanjur dianggap benar olehnya.
   */
  it('ketikan ulang yang beda ditolak tanpa menghubungi server', async () => {
    tampilkan()
    isi('Lama12345', 'Baru67890', 'Baru99999')
    kirim()

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(gantiSandi).not.toHaveBeenCalled()
  })

  it('pesan penolakan server ditampilkan apa adanya', async () => {
    ;(gantiSandi as Mock).mockRejectedValue(new Error('Sandi saat ini salah.'))

    tampilkan()
    isi('Salah12345', 'Baru67890')
    kirim()

    expect((await screen.findByRole('alert')).textContent).toContain('Sandi saat ini salah.')
  })

  /**
   * Isian BERTAHAN kalau server menolak. Kalau ikut dikosongkan, orang yang
   * salah ketik satu huruf harus mengetik ulang ketiganya.
   */
  it('isian bertahan kalau server menolak', async () => {
    ;(gantiSandi as Mock).mockRejectedValue(new Error('Sandi saat ini salah.'))

    tampilkan()
    isi('Salah12345', 'Baru67890')
    kirim()

    await screen.findByRole('alert')
    expect((screen.getByLabelText('Sandi sekarang') as HTMLInputElement).value).toBe('Salah12345')
    expect((screen.getByLabelText('Sandi baru') as HTMLInputElement).value).toBe('Baru67890')
  })

  it('berhasil menampilkan konfirmasi dan mengosongkan isian', async () => {
    ;(gantiSandi as Mock).mockResolvedValue(undefined)

    tampilkan()
    isi('Lama12345', 'Baru67890')
    kirim()

    expect(await screen.findByRole('status')).toBeTruthy()
    expect((screen.getByLabelText('Sandi baru') as HTMLInputElement).value).toBe('')
  })

  it('sesi habis memulangkan ke login', async () => {
    ;(gantiSandi as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const keluar = vi.fn()

    tampilkan(keluar)
    isi('Lama12345', 'Baru67890')
    kirim()

    await vi.waitFor(() => expect(keluar).toHaveBeenCalled())
  })

  /** Batas revocation harus terbaca, bukan disembunyikan. */
  it('menyebut bahwa perangkat lain tak langsung terputus', () => {
    tampilkan()

    expect(screen.getByText(/tidak langsung terputus/)).toBeTruthy()
  })
})
