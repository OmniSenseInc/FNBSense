// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilNotifikasi, tandaiSemuaDibaca } from './api'
import LayarNotifikasi from './LayarNotifikasi'

vi.mock('./api', () => ({
  ambilNotifikasi: vi.fn(),
  tandaiSemuaDibaca: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

function notif(id: string, sudahDibaca: boolean, tingkat = 'warning') {
  return {
    id,
    jenis: 'low_stock',
    tingkat,
    judul: `Stok menipis ${id}`,
    isi: 'Kopi Arabika tersisa 200 gram.',
    waktu: new Date().toISOString(),
    sudahDibaca,
  }
}

function tampilkan() {
  return render(
    <MemoryRouter>
      <LayarNotifikasi onKeluar={() => {}} />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  ;(ambilNotifikasi as Mock).mockResolvedValue([])
  ;(tandaiSemuaDibaca as Mock).mockResolvedValue(undefined)
})

afterEach(cleanup)

describe('LayarNotifikasi', () => {
  it('menghitung yang belum dibaca, bukan seluruh daftar', async () => {
    ;(ambilNotifikasi as Mock).mockResolvedValue([
      notif('a', false),
      notif('b', true),
      notif('c', false),
    ])

    tampilkan()

    expect(await screen.findByText('2 belum dibaca')).toBeTruthy()
  })

  it('tidak menandai apa pun hanya karena halamannya dibuka', async () => {
    // Inti dari keputusan desainnya. Kasir yang membuka daftar karena
    // penasaran tak boleh menghapus penanda untuk peringatan stok yang belum
    // ditindaklanjuti siapa pun.
    ;(ambilNotifikasi as Mock).mockResolvedValue([notif('a', false)])

    tampilkan()

    await screen.findByText('1 belum dibaca')
    expect(tandaiSemuaDibaca).not.toHaveBeenCalled()
  })

  it('menandai semua hanya setelah tombolnya ditekan', async () => {
    ;(ambilNotifikasi as Mock).mockResolvedValue([notif('a', false), notif('b', false)])

    tampilkan()

    fireEvent.click(await screen.findByRole('button', { name: /Tandai semua dibaca/i }))

    await waitFor(() => expect(tandaiSemuaDibaca).toHaveBeenCalledTimes(1))
    expect(await screen.findByText('Semua sudah dibaca')).toBeTruthy()
  })

  it('tidak membersihkan layar kalau server menolak menandai', async () => {
    // Kalau layarnya dibersihkan lebih dulu lalu permintaannya gagal, kasir
    // melihat inbox bersih padahal peringatannya masih menunggu di server —
    // dan ia tak punya alasan untuk membukanya lagi.
    ;(ambilNotifikasi as Mock).mockResolvedValue([notif('a', false)])
    ;(tandaiSemuaDibaca as Mock).mockRejectedValue(new Error('Gagal menghubungi server. Coba lagi.'))

    tampilkan()

    fireEvent.click(await screen.findByRole('button', { name: /Tandai semua dibaca/i }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByText('1 belum dibaca')).toBeTruthy()
  })

  it('memberi tahu saat inbox memang kosong', async () => {
    // Daftar kosong tanpa kalimat apa pun terbaca sebagai layar yang gagal
    // memuat.
    tampilkan()

    expect(await screen.findByText(/Belum ada pemberitahuan/i)).toBeTruthy()
  })
})
