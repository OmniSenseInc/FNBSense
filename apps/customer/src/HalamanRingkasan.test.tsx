// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import type { Keranjang, Produk } from './api'
import { kirimPesanan } from './api'
import HalamanRingkasan from './HalamanRingkasan'
import { useMeja } from './konteksMeja'
import { catatPesanan } from './pesananSaya'

vi.mock('./konteksMeja', () => ({ useMeja: vi.fn() }))
vi.mock('./pesananSaya', () => ({
  catatPesanan: vi.fn(),
  kunciPesanan: (qr: string) => `pesanan:${qr}`,
}))

/**
 * `susunPesanan` TIDAK dipalsukan — ia yang menerjemahkan keranjang jadi payload,
 * dan justru bentuk payload itulah yang ingin dijaga di sini. Yang dipalsukan
 * cuma pintu jaringannya.
 */
vi.mock('./api', async (asli) => ({
  ...(await asli<typeof import('./api')>()),
  kirimPesanan: vi.fn(),
}))

const produk = (ubah: Partial<Produk> = {}): Produk => ({
  id: 'p1',
  nama: 'Espresso',
  deskripsi: '',
  harga: 18000,
  gambarUrl: null,
  habis: false,
  ...ubah,
})

function tampilkan(daftar: Produk[], isi: Keranjang) {
  const hapusKeranjang = vi.fn()
  ;(useMeja as Mock).mockReturnValue({
    qrToken: 'qr-1',
    meja: { tenant_id: 't1', outlet_id: 'o1', table_id: 'm1', label: 'Meja 4' },
    kategori: [{ id: 'k1', nama: 'Kopi', produk: daftar }],
    isi,
    setIsi: vi.fn(),
    hapusKeranjang,
  })

  render(
    <MemoryRouter>
      <HalamanRingkasan />
    </MemoryRouter>,
  )

  return { hapusKeranjang }
}

/** Isi nama + pilih cara bayar — dua syarat minimum sebelum tombol kirim hidup. */
function isiFormulir(nama = 'Vincent', bayar = 'Tunai') {
  fireEvent.change(screen.getByPlaceholderText('Nama kamu'), { target: { value: nama } })
  fireEvent.click(screen.getByRole('radio', { name: new RegExp(bayar) }))
}

const tombolKirim = () => screen.getByRole('button', { name: 'Kirim pesanan' }) as HTMLButtonElement

beforeEach(() => vi.clearAllMocks())
afterEach(cleanup)

describe('syarat sebelum boleh mengirim', () => {
  it('tombol mati sebelum nama dan cara bayar diisi', () => {
    tampilkan([produk()], { p1: { qty: 1, note: '' } })

    expect(tombolKirim().disabled).toBe(true)
  })

  it('tombol masih mati kalau cara bayar belum dipilih', () => {
    tampilkan([produk()], { p1: { qty: 1, note: '' } })

    fireEvent.change(screen.getByPlaceholderText('Nama kamu'), { target: { value: 'Vincent' } })

    // Tak ada pilihan awal dengan sengaja: memilihkan diam-diam berarti sebagian
    // orang mengirim dengan cara bayar yang tak pernah mereka baca.
    expect(tombolKirim().disabled).toBe(true)
  })

  it('nama berisi spasi saja tidak dianggap terisi', () => {
    tampilkan([produk()], { p1: { qty: 1, note: '' } })

    isiFormulir('   ')

    expect(tombolKirim().disabled).toBe(true)
  })

  it('nama dan cara bayar lengkap menghidupkan tombol', () => {
    tampilkan([produk()], { p1: { qty: 1, note: '' } })

    isiFormulir()

    expect(tombolKirim().disabled).toBe(false)
  })
})

describe('mengirim pesanan', () => {
  const isi: Keranjang = { p1: { qty: 2, note: 'tanpa gula' } }

  it('payload memuat qr_token, nama, cara bayar, dan item beserta catatannya', async () => {
    ;(kirimPesanan as Mock).mockResolvedValue({ id: 'o-9' })
    tampilkan([produk()], isi)

    isiFormulir()
    fireEvent.click(tombolKirim())

    await vi.waitFor(() => expect(kirimPesanan).toHaveBeenCalled())
    const payload = (kirimPesanan as Mock).mock.calls[0][0]
    expect(payload.qr_token).toBe('qr-1')
    expect(payload.customer_name).toBe('Vincent')
    expect(payload.payment_preference).toBe('cash')
    expect(payload.items).toEqual([{ product_id: 'p1', qty: 2, note: 'tanpa gula' }])
  })

  it('E-Payment terkirim sebagai qris_static, bukan tunai', async () => {
    ;(kirimPesanan as Mock).mockResolvedValue({ id: 'o-9' })
    tampilkan([produk()], isi)

    isiFormulir('Vincent', 'E-Payment')
    fireEvent.click(tombolKirim())

    await vi.waitFor(() =>
      expect((kirimPesanan as Mock).mock.calls[0][0].payment_preference).toBe('qris_static'),
    )
  })

  it('keranjang baru dikosongkan setelah server menerima', async () => {
    ;(kirimPesanan as Mock).mockResolvedValue({ id: 'o-9' })
    const { hapusKeranjang } = tampilkan([produk()], isi)

    isiFormulir()
    fireEvent.click(tombolKirim())

    await vi.waitFor(() => expect(hapusKeranjang).toHaveBeenCalled())
    expect(catatPesanan).toHaveBeenCalledWith('pesanan:qr-1', 'o-9')
  })

  /**
   * Yang paling mahal kalau salah: keranjang hilang padahal pesanannya tak
   * pernah sampai. Pelanggan harus menyusun ulang dari nol, dan sebagian
   * menyerah lalu pulang.
   */
  it('keranjang BERTAHAN kalau pengiriman gagal', async () => {
    ;(kirimPesanan as Mock).mockRejectedValue(new Error('Jaringan putus.'))
    const { hapusKeranjang } = tampilkan([produk()], isi)

    isiFormulir()
    fireEvent.click(tombolKirim())

    expect(await screen.findByText('Jaringan putus.')).toBeTruthy()
    expect(hapusKeranjang).not.toHaveBeenCalled()
    expect(catatPesanan).not.toHaveBeenCalled()
  })

  it('pesan penolakan server ditampilkan apa adanya', async () => {
    ;(kirimPesanan as Mock).mockRejectedValue(new Error('Espresso sedang habis.'))
    tampilkan([produk()], isi)

    isiFormulir()
    fireEvent.click(tombolKirim())

    // Gerbang stok di server menolak dengan kalimat yang sudah ditulis untuk
    // manusia; menggantinya dengan "Gagal" membuang satu-satunya petunjuk.
    expect(await screen.findByText('Espresso sedang habis.')).toBeTruthy()
  })
})
