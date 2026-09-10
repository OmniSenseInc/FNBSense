// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilMeja, buatMeja, putarQr, ubahMeja, urlMeja, type Meja } from './api'
import LayarMeja from './LayarMeja'

vi.mock('./api', () => ({
  ambilMeja: vi.fn(),
  buatMeja: vi.fn(),
  ubahMeja: vi.fn(),
  putarQr: vi.fn(),
  urlMeja: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

function meja(ubah: Partial<Meja> = {}): Meja {
  return { id: 'mj-1', label: 'Meja 1', aktif: true, qrToken: 'tok-1', ...ubah }
}

async function tampilkanSiap(daftar: Meja[], onKeluar: () => void = () => {}) {
  ;(ambilMeja as Mock).mockResolvedValue(daftar)

  const hasil = render(
    <MemoryRouter>
      <LayarMeja onKeluar={onKeluar} />
    </MemoryRouter>,
  )

  // Tunggu jawaban server tiba — sebelum itu daftarnya masih null.
  if (daftar.length > 0) await screen.findByText(daftar[0].label)
  else await screen.findByText(/Belum ada meja/)

  return hasil
}

beforeEach(() => {
  vi.clearAllMocks()
  ;(urlMeja as Mock).mockImplementation((token: string) => `http://pelanggan.test/t/${token}`)
})

afterEach(cleanup)

describe('daftar meja', () => {
  it('menampilkan alamat yang ditanam di QR', async () => {
    // Alamatnya ikut tertulis karena ia yang harus benar; QR cuma pembungkusnya.
    // Kalau yang tercetak menunjuk host yang salah, ketahuannya dari pelanggan.
    await tampilkanSiap([meja()])

    expect(screen.getByText('http://pelanggan.test/t/tok-1')).toBeTruthy()
  })

  it('outlet kosong diberi tahu langkah berikutnya, bukan layar hampa', async () => {
    await tampilkanSiap([])

    expect(screen.getByText(/Belum ada meja/)).toBeTruthy()
  })

  it('alamat app pelanggan belum disetel: QR TIDAK digambar', async () => {
    // Menolak menggambar lebih baik daripada menggambar yang salah — stiker
    // dicetak lalu ditempel, dan yang keliru baru ketahuan setelah gagal dipakai.
    ;(urlMeja as Mock).mockReturnValue(null)
    await tampilkanSiap([meja()])

    expect(screen.getByText(/belum disetel/)).toBeTruthy()
    expect(screen.queryByText(/pelanggan.test/)).toBeNull()
  })
})

describe('menambah meja', () => {
  it('mengirim label yang diketik', async () => {
    ;(buatMeja as Mock).mockResolvedValue(meja({ id: 'mj-2', label: 'Meja 2', qrToken: 'tok-2' }))
    await tampilkanSiap([meja()])

    fireEvent.change(screen.getByLabelText('Nama meja baru'), { target: { value: '  Meja 2  ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah' }))

    // Spasi dibuang: "Meja 2 " dan "Meja 2" akan lolos sebagai dua meja berbeda
    // di layar, padahal server menganggapnya bentrok.
    expect(buatMeja as Mock).toHaveBeenCalledWith('Meja 2')
  })

  it('nama yang sudah dipakai ditolak SEBELUM dikirim', async () => {
    await tampilkanSiap([meja({ label: 'Meja 1' })])

    fireEvent.change(screen.getByLabelText('Nama meja baru'), { target: { value: 'meja 1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah' }))

    // Beda huruf besar-kecil tetap bentrok: collation MySQL menganggapnya sama,
    // jadi memeriksa persis-sama di layar akan meloloskan yang pasti ditolak.
    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(buatMeja as Mock).not.toHaveBeenCalled()
  })
})

describe('saklar aktif', () => {
  it('menonaktifkan meja tanpa menyentuh namanya', async () => {
    // Ikut mengirim label berarti menimpa nama meja dengan salinan yang mungkin
    // sudah basi di layar — padahal yang diminta cuma mematikan QR-nya.
    ;(ubahMeja as Mock).mockResolvedValue(meja({ aktif: false }))
    await tampilkanSiap([meja()])

    fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }))

    expect(ubahMeja as Mock).toHaveBeenCalledWith('mj-1', { aktif: false })
  })

  it('meja nonaktif bisa dinyalakan lagi', async () => {
    ;(ubahMeja as Mock).mockResolvedValue(meja({ aktif: true }))
    await tampilkanSiap([meja({ aktif: false })])

    fireEvent.click(screen.getByRole('button', { name: 'Aktifkan' }))

    expect(ubahMeja as Mock).toHaveBeenCalledWith('mj-1', { aktif: true })
  })
})

describe('ganti QR', () => {
  it('butuh dua langkah, dan langkah pertama belum mengirim apa pun', async () => {
    // Sekali tekan berarti seluruh stiker yang sudah tertempel di meja itu jadi
    // sampah, dan tak ada tombol urungkan di mana pun.
    await tampilkanSiap([meja()])

    fireEvent.click(screen.getByRole('button', { name: 'Ganti QR' }))

    expect(putarQr as Mock).not.toHaveBeenCalled()
    expect(screen.getByText(/Stiker lama langsung mati/)).toBeTruthy()
  })

  it('terbit setelah dikonfirmasi, dan alamat di layar ikut berganti', async () => {
    ;(putarQr as Mock).mockResolvedValue(meja({ qrToken: 'tok-baru' }))
    await tampilkanSiap([meja()])

    fireEvent.click(screen.getByRole('button', { name: 'Ganti QR' }))
    fireEvent.click(screen.getByRole('button', { name: 'Ya, ganti QR' }))

    expect(putarQr as Mock).toHaveBeenCalledWith('mj-1')
    // Owner mencetak dari layar ini; alamat lama yang bertahan berarti dia
    // mencetak QR yang baru saja dimatikannya sendiri.
    expect(await screen.findByText('http://pelanggan.test/t/tok-baru')).toBeTruthy()
  })
})

describe('ubah nama', () => {
  it('mengirim nama baru tanpa menyentuh saklarnya', async () => {
    ;(ubahMeja as Mock).mockResolvedValue(meja({ label: 'Meja Pojok' }))
    await tampilkanSiap([meja()])

    fireEvent.click(screen.getByRole('button', { name: 'Ubah nama' }))
    fireEvent.change(screen.getByLabelText('Nama baru untuk Meja 1'), {
      target: { value: 'Meja Pojok' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan' }))

    expect(ubahMeja as Mock).toHaveBeenCalledWith('mj-1', { label: 'Meja Pojok' })
  })

  it('nama yang tak berubah tidak dikirim ke server', async () => {
    await tampilkanSiap([meja()])

    fireEvent.click(screen.getByRole('button', { name: 'Ubah nama' }))
    fireEvent.click(screen.getByRole('button', { name: 'Simpan' }))

    expect(ubahMeja as Mock).not.toHaveBeenCalled()
  })
})

describe('sesi habis', () => {
  it('dipulangkan ke login, bukan diberi pesan galat', async () => {
    ;(ubahMeja as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()
    await tampilkanSiap([meja()], onKeluar)

    fireEvent.click(screen.getByRole('button', { name: 'Nonaktifkan' }))
    await screen.findByText('Meja 1')

    expect(onKeluar).toHaveBeenCalled()
    expect(screen.queryByRole('alert')).toBeNull()
  })
})
