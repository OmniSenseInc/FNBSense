// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Bahan, BarisResep } from './api'
import PanelResep from './PanelResep'

const susu: Bahan = { id: 'b1', nama: 'Susu', satuan: 'ml' }
const biji: Bahan = { id: 'b2', nama: 'Biji Kopi', satuan: 'g' }
const barisSusu: BarisResep = { id: 'r1', produkId: 'p1', bahanId: 'b1', takaran: 150 }

function tampilkan(ubah: Partial<React.ComponentProps<typeof PanelResep>> = {}) {
  const props = {
    namaProduk: 'Kopi Susu',
    baris: [barisSusu],
    bahan: [susu, biji],
    sibuk: false,
    onTambah: vi.fn(),
    onUbah: vi.fn(),
    onHapus: vi.fn(),
    ...ubah,
  }
  render(<PanelResep {...props} />)

  return props
}

afterEach(cleanup)

describe('takaran yang ditolak sebelum menyentuh server', () => {
  it('nol ditolak — DB memasang CHECK qty_per_unit > 0', () => {
    const { onTambah } = tampilkan()

    fireEvent.change(screen.getByLabelText('Bahan untuk Kopi Susu'), { target: { value: 'b2' } })
    fireEvent.change(screen.getByLabelText('Takaran untuk Kopi Susu'), { target: { value: '0' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    expect(screen.getByRole('alert').textContent).toContain('lebih dari nol')
    expect(onTambah).not.toHaveBeenCalled()
  })

  it('"1.000" ditolak — dalam tulisan Indonesia itu seribu, bukan satu', () => {
    const { onTambah } = tampilkan()

    fireEvent.change(screen.getByLabelText('Bahan untuk Kopi Susu'), { target: { value: 'b2' } })
    fireEvent.change(screen.getByLabelText('Takaran untuk Kopi Susu'), {
      target: { value: '1.000' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    expect(screen.getByRole('alert')).toBeTruthy()
    expect(onTambah).not.toHaveBeenCalled()
  })

  it('tanpa memilih bahan, tak ada yang dikirim', () => {
    const { onTambah } = tampilkan()

    fireEvent.change(screen.getByLabelText('Takaran untuk Kopi Susu'), { target: { value: '18' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    expect(screen.getByRole('alert').textContent).toContain('Pilih bahannya')
    expect(onTambah).not.toHaveBeenCalled()
  })

  it('desimal satu angka diterima', () => {
    const { onTambah } = tampilkan()

    fireEvent.change(screen.getByLabelText('Bahan untuk Kopi Susu'), { target: { value: 'b2' } })
    fireEvent.change(screen.getByLabelText('Takaran untuk Kopi Susu'), { target: { value: '1.5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Tambah bahan' }))

    expect(onTambah).toHaveBeenCalledWith('b2', 1.5)
  })
})

describe('bahan yang sudah dipakai', () => {
  it('tak ditawarkan lagi — DB menolak bahan yang sama dua kali per produk', () => {
    tampilkan()

    const pilihan = Array.from(
      screen.getByLabelText('Bahan untuk Kopi Susu').querySelectorAll('option'),
    ).map((o) => o.textContent)

    expect(pilihan).not.toContain('Susu')
    expect(pilihan).toContain('Biji Kopi')
  })

  it('kalau semua bahan sudah dipakai, tombol tambah mati', () => {
    tampilkan({ bahan: [susu] })

    expect(
      (screen.getByRole('button', { name: 'Tambah bahan' }) as HTMLButtonElement).disabled,
    ).toBe(true)
  })
})

describe('mengubah takaran', () => {
  it('Simpan mati sampai angkanya benar-benar diubah', () => {
    tampilkan()

    const simpan = screen.getByRole('button', { name: 'Simpan' }) as HTMLButtonElement
    expect(simpan.disabled).toBe(true)

    fireEvent.change(screen.getByLabelText('Takaran Susu'), { target: { value: '200' } })
    expect(simpan.disabled).toBe(false)
  })

  it('mengirim id baris resep, bukan id bahan', () => {
    const { onUbah } = tampilkan()

    fireEvent.change(screen.getByLabelText('Takaran Susu'), { target: { value: '200' } })
    fireEvent.click(screen.getByRole('button', { name: 'Simpan' }))

    expect(onUbah).toHaveBeenCalledWith('r1', 200)
  })

  it('takaran tak terbaca dari server tampil kosong, bukan NaN', () => {
    tampilkan({ baris: [{ ...barisSusu, takaran: NaN }] })

    expect((screen.getByLabelText('Takaran Susu') as HTMLInputElement).value).toBe('')
  })
})

describe('resep kosong', () => {
  it('menyebut akibatnya, bukan cuma daftar kosong', () => {
    tampilkan({ baris: [] })

    expect(screen.getByText(/tetap bisa dipesan walau bahannya habis/)).toBeTruthy()
  })

  it('tanpa bahan sama sekali, owner diarahkan ke halaman Bahan', () => {
    tampilkan({ baris: [], bahan: [] })

    expect(screen.getByText(/Daftarkan bahannya dulu/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Tambah bahan' })).toBeNull()
  })
})

describe('menghapus baris', () => {
  it('mengirim id barisnya', () => {
    const { onHapus } = tampilkan()

    fireEvent.click(screen.getByRole('button', { name: 'Hapus Susu dari resep' }))

    expect(onHapus).toHaveBeenCalledWith('r1')
  })
})
