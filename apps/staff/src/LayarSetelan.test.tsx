// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilSetelan, simpanSetelan, unggahQris, type Setelan } from './api'
import LayarSetelan from './LayarSetelan'

// Modul api diganti SELURUHNYA, jadi tiap ekspor yang dipakai layar ini harus
// ikut didaftarkan — termasuk urlQris, yang bukan pemanggilan jaringan tapi
// tetap akan jadi undefined kalau dilewatkan.
vi.mock('./api', () => ({
  ambilSetelan: vi.fn(),
  simpanSetelan: vi.fn(),
  unggahQris: vi.fn(),
  urlQris: (jalur: string) => `http://ordering.test${jalur}`,
  SESI_HABIS: 'SESI_HABIS',
}))

function setelan(ubah: Partial<Setelan> = {}): Setelan {
  return {
    pajakPersen: 11,
    layananPersen: 5,
    kedaluwarsaMenit: 30,
    qrisUrl: '/storage/qris/abc.png',
    batas: {
      tax_percent_max: 30,
      service_charge_percent_max: 30,
      order_expiry_minutes_min: 1,
      order_expiry_minutes_max: 1440,
      qris_max_kilobytes: 2048,
      qris_max_pixels: 2000,
    },
    ...ubah,
  }
}

function tampilkan(onKeluar: () => void = () => {}) {
  return render(
    <MemoryRouter>
      <LayarSetelan onKeluar={onKeluar} />
    </MemoryRouter>,
  )
}

/** Render lalu tunggu jawaban server tiba — form baru ada setelah itu. */
async function tampilkanSiap(onKeluar: () => void = () => {}) {
  const hasil = tampilkan(onKeluar)
  await screen.findByLabelText(/Pajak/)

  return hasil
}

beforeEach(() => {
  vi.clearAllMocks()
  ;(ambilSetelan as Mock).mockResolvedValue(setelan())
  ;(simpanSetelan as Mock).mockResolvedValue(setelan())
})

afterEach(cleanup)

describe('form tarif', () => {
  it('terisi dari server, bukan dari nilai bawaan layar', async () => {
    // Kotak yang menampilkan 0 padahal server menagih 11% membuat owner
    // menekan Simpan dan benar-benar menghapus pajaknya tanpa pernah bermaksud.
    await tampilkanSiap()

    expect((screen.getByLabelText(/Pajak/) as HTMLInputElement).value).toBe('11')
    expect((screen.getByLabelText(/Service charge/) as HTMLInputElement).value).toBe('5')
    expect((screen.getByLabelText(/Batas waktu bayar/) as HTMLInputElement).value).toBe('30')
  })

  it('batas dari server dipasang sebagai atribut input', async () => {
    // Kalau ini kosong, form tak memandu apa pun dan owner baru tahu batasnya
    // dari pesan galat berbahasa Inggris setelah menekan Simpan.
    await tampilkanSiap()

    expect((screen.getByLabelText(/Pajak/) as HTMLInputElement).max).toBe('30')
    expect((screen.getByLabelText(/Batas waktu bayar/) as HTMLInputElement).max).toBe('1440')
  })

  it('mengirim ketiga medan, termasuk yang tak diubah', async () => {
    // Yang tersimpan harus persis yang dilihat owner di layar saat menekan
    // Simpan — bukan campuran antara ketikannya dan nilai yang sudah berubah
    // di sela-selanya.
    await tampilkanSiap()

    fireEvent.change(screen.getByLabelText(/Pajak/), { target: { value: '12.5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan tarif' }))

    expect(simpanSetelan as Mock).toHaveBeenCalledWith({
      pajakPersen: 12.5,
      layananPersen: 5,
      kedaluwarsaMenit: 30,
    })
  })

  it('penolakan server ditampilkan apa adanya, tidak diganti kalimat sendiri', async () => {
    // Mengarang "coba lagi" di atas penolakan yang punya alasan konkret
    // membuat owner mengulangi persis hal yang baru saja ditolak.
    ;(simpanSetelan as Mock).mockRejectedValue(new Error('Pajak melebihi batas 30%.'))
    await tampilkanSiap()

    fireEvent.click(screen.getByRole('button', { name: 'Simpan tarif' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(screen.getByText('Pajak melebihi batas 30%.')).toBeTruthy()
  })

  it('sesi habis: dipulangkan ke login, bukan diberi pesan galat', async () => {
    ;(simpanSetelan as Mock).mockRejectedValue(new Error('SESI_HABIS'))
    const onKeluar = vi.fn()
    await tampilkanSiap(onKeluar)

    fireEvent.click(screen.getByRole('button', { name: 'Simpan tarif' }))
    // Satu putaran mikrotask: cukup untuk penolakan yang sudah terjadi
    // merambat lewat catch.
    await screen.findByLabelText(/Pajak/)

    expect(onKeluar).toHaveBeenCalled()
    expect(screen.queryByRole('alert')).toBeNull()
  })
})

describe('QRIS', () => {
  it('pratinjau memakai alamat Ordering, bukan alamat app staf', async () => {
    // Dipasang apa adanya, browser mencarinya di host app staf dan owner
    // melihat kotak rusak persis di layar yang seharusnya meyakinkannya.
    await tampilkanSiap()

    expect((screen.getByRole('img') as HTMLImageElement).src).toBe(
      'http://ordering.test/storage/qris/abc.png',
    )
  })

  it('outlet tanpa QRIS diberi tahu akibatnya bagi pelanggan', async () => {
    ;(ambilSetelan as Mock).mockResolvedValue(setelan({ qrisUrl: null }))
    await tampilkanSiap()

    expect(screen.getByText(/Belum ada QRIS/)).toBeTruthy()
    expect(screen.queryByRole('img')).toBeNull()
  })

  it('berkas terlalu besar ditolak SEBELUM dikirim', async () => {
    // Menunggu 3 MB terkirim hanya untuk ditolak adalah cara paling lambat
    // memberi tahu owner sesuatu yang sudah bisa diketahui sejak berkasnya
    // dipilih — dan di Wi-Fi kafe, paling menyakitkan.
    const { container } = await tampilkanSiap()

    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    const besar = new File([new ArrayBuffer(3 * 1024 * 1024)], 'qris.png', { type: 'image/png' })
    fireEvent.change(input, { target: { files: [besar] } })
    fireEvent.click(screen.getByRole('button', { name: 'Ganti QRIS' }))

    expect(await screen.findByRole('alert')).toBeTruthy()
    expect(unggahQris as Mock).not.toHaveBeenCalled()
  })

  it('berkas yang muat dikirim ke server, dan pratinjaunya ikut berganti', async () => {
    ;(unggahQris as Mock).mockResolvedValue(setelan({ qrisUrl: '/storage/qris/baru.png' }))
    const { container } = await tampilkanSiap()

    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    const kecil = new File([new ArrayBuffer(1024)], 'qris.png', { type: 'image/png' })
    fireEvent.change(input, { target: { files: [kecil] } })
    fireEvent.click(screen.getByRole('button', { name: 'Ganti QRIS' }))

    expect(unggahQris as Mock).toHaveBeenCalledWith(kecil)
    // Owner tak punya cara lain memastikan yang terpasang sekarang benar-benar
    // berkas yang barusan dia pilih.
    const gambar = await screen.findByRole('img')
    expect(gambar.getAttribute('src')).toContain('baru.png')
  })

  it('tombol ganti terkunci selama belum ada berkas dipilih', async () => {
    await tampilkanSiap()

    const tombol = screen.getByRole('button', { name: 'Ganti QRIS' }) as HTMLButtonElement
    expect(tombol.disabled).toBe(true)
  })
})
