// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilStaf, buatKasir, resetSandiStaf, ubahAktifStaf } from './api'
import LayarStaf from './LayarStaf'

vi.mock('./api', () => ({
  ambilStaf: vi.fn(),
  buatKasir: vi.fn(),
  resetSandiStaf: vi.fn(),
  ubahAktifStaf: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

const staf = (ubah = {}) => ({
  id: 's1',
  nama: 'Rina',
  email: 'rina@kafe.test',
  peran: 'cashier',
  aktif: true,
  ...ubah,
})

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarStaf onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('menambah kasir', () => {
  it('mengirim nama, email, dan sandi', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([])
    ;(buatKasir as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Budi' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'budi@kafe.test' } })
    fireEvent.change(screen.getByLabelText('Sandi awal'), { target: { value: 'Rahasia123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah kasir' }))

    await vi.waitFor(() =>
      expect(buatKasir).toHaveBeenCalledWith('Budi', 'budi@kafe.test', 'Rahasia123'),
    )
  })

  it('isian kosong ditolak tanpa menghubungi server', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([])

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Tambah kasir' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(buatKasir).not.toHaveBeenCalled()
  })

  it('spasi di ujung nama dan email dirapikan', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([])
    ;(buatKasir as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: '  Budi  ' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: ' budi@kafe.test ' } })
    fireEvent.change(screen.getByLabelText('Sandi awal'), { target: { value: 'Rahasia123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah kasir' }))

    await vi.waitFor(() =>
      expect(buatKasir).toHaveBeenCalledWith('Budi', 'budi@kafe.test', 'Rahasia123'),
    )
  })

  /**
   * Sandi yang ditolak server harus tetap ada di kotaknya. Kalau ikut
   * dikosongkan, owner yang kena aturan panjang/campuran huruf harus mengarang
   * ulang dari nol setiap kali — dan biasanya menyerah ke sandi yang lebih lemah.
   */
  it('sandi tidak dikosongkan kalau server menolak', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([])
    ;(buatKasir as Mock).mockRejectedValue(new Error('Sandi terlalu pendek.'))

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Budi' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'budi@kafe.test' } })
    fireEvent.change(screen.getByLabelText('Sandi awal'), { target: { value: 'pendek' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah kasir' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect((screen.getByLabelText('Sandi awal') as HTMLInputElement).value).toBe('pendek')
  })

  it('pesan penolakan server ditampilkan apa adanya', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([])
    ;(buatKasir as Mock).mockRejectedValue(new Error('Email sudah dipakai.'))

    tampilkan()
    fireEvent.change(await screen.findByLabelText('Nama'), { target: { value: 'Budi' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'budi@kafe.test' } })
    fireEvent.change(screen.getByLabelText('Sandi awal'), { target: { value: 'Rahasia123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah kasir' }))

    expect((await screen.findByRole('alert')).textContent).toContain('Email sudah dipakai.')
  })
})

describe('daftar karyawan', () => {
  it('menampilkan nama, email, dan peran', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf()])

    tampilkan()

    expect(await screen.findByText('Rina')).toBeTruthy()
    expect(screen.getByText(/rina@kafe\.test · Kasir/)).toBeTruthy()
  })

  it('akun nonaktif ditandai, dan tombolnya berbunyi Aktifkan', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf({ aktif: false })])

    tampilkan()

    expect(await screen.findByText(/Nonaktif/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Aktifkan Rina' })).toBeTruthy()
  })

  it('menonaktifkan mengirim is_active false', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf()])
    ;(ubahAktifStaf as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Nonaktifkan Rina' }))

    await vi.waitFor(() => expect(ubahAktifStaf).toHaveBeenCalledWith('s1', false))
  })

  it('mengaktifkan kembali mengirim is_active true', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf({ aktif: false })])
    ;(ubahAktifStaf as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Aktifkan Rina' }))

    await vi.waitFor(() => expect(ubahAktifStaf).toHaveBeenCalledWith('s1', true))
  })

  /**
   * Owner hari ini satu-satunya per tenant: menonaktifkan diri sendiri
   * mematikan tenant permanen. IAM menolaknya dengan 422; tombolnya sendiri
   * tak boleh ada supaya tak pernah menggoda.
   */
  it('baris pemilik tidak punya tombol nonaktifkan', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([
      staf({ id: 'o1', nama: 'Vincent', peran: 'owner' }),
      staf(),
    ])

    tampilkan()
    await screen.findByText('Vincent')

    expect(screen.queryByRole('button', { name: /Vincent/ })).toBeNull()
    expect(screen.getByRole('button', { name: 'Nonaktifkan Rina' })).toBeTruthy()
  })
})

describe('reset sandi kasir', () => {
  it('form baru muncul setelah tombol reset diketuk', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf()])

    tampilkan()
    expect(screen.queryByLabelText(/Sandi baru untuk/)).toBeNull()

    fireEvent.click(await screen.findByRole('button', { name: 'Reset sandi Rina' }))

    expect(screen.getByLabelText('Sandi baru untuk Rina')).toBeTruthy()
  })

  it('mengirim sandi baru untuk staf yang benar', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf()])
    ;(resetSandiStaf as Mock).mockResolvedValue(undefined)

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Reset sandi Rina' }))
    fireEvent.change(screen.getByLabelText('Sandi baru untuk Rina'), {
      target: { value: 'Rahasia123' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan' }))

    await vi.waitFor(() => expect(resetSandiStaf).toHaveBeenCalledWith('s1', 'Rahasia123'))
  })

  it('sandi kosong ditolak tanpa menghubungi server', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf()])

    tampilkan()
    fireEvent.click(await screen.findByRole('button', { name: 'Reset sandi Rina' }))
    fireEvent.click(screen.getByRole('button', { name: 'Simpan' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(resetSandiStaf).not.toHaveBeenCalled()
  })

  /**
   * Server menolak owner mereset sandinya sendiri (422) supaya syarat "sebut
   * sandi lama" tak bisa dilangkahi. Tombolnya pun tak boleh ada di sini.
   */
  it('baris pemilik tidak punya tombol reset sandi', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf({ id: 'o1', nama: 'Vincent', peran: 'owner' })])

    tampilkan()
    await screen.findByText('Vincent')

    expect(screen.queryByRole('button', { name: /Reset sandi/ })).toBeNull()
  })
})

describe('sesi', () => {
  it('sesi habis saat memuat memulangkan ke login', async () => {
    ;(ambilStaf as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const keluar = vi.fn()

    tampilkan(keluar)

    await vi.waitFor(() => expect(keluar).toHaveBeenCalled())
  })

  it('sesi habis saat menyimpan memulangkan ke login', async () => {
    ;(ambilStaf as Mock).mockResolvedValue([staf()])
    ;(ubahAktifStaf as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const keluar = vi.fn()

    tampilkan(keluar)
    fireEvent.click(await screen.findByRole('button', { name: 'Nonaktifkan Rina' }))

    await vi.waitFor(() => expect(keluar).toHaveBeenCalled())
  })
})
