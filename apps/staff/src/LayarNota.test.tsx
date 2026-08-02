// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'
import { ambilPesanan, ambilSetelan } from './api'
import LayarNota from './LayarNota'

vi.mock('./api', () => ({
  ambilPesanan: vi.fn(),
  ambilSetelan: vi.fn(),
  SESI_HABIS: 'SESI_HABIS',
}))

const PESANAN = {
  id: 'ord-1',
  order_number: 'A-001',
  customer_name: 'Vincent',
  grand_total: 45_000,
  subtotal: 45_000,
  layanan: 0,
  pajak: 0,
  caraBayar: 'qris_static',
  niatBayar: null,
  meja: 'Meja 4',
  tipe: 'dine_in',
  waktuBayar: '2026-08-02T14:23:00+07:00',
  klaimBayar: null,
  siapPada: null,
  created_at: '2026-08-02T14:20:00+07:00',
  expires_at: null,
  items: [{ nama: 'Kopi Susu', qty: 2, hargaSatuan: 22_500, total: 45_000, note: '' }],
}

const SETELAN = {
  pajakPersen: 0,
  layananPersen: 0,
  kedaluwarsaMenit: 30,
  qrisUrl: null,
  namaOutlet: 'Kopi Senja',
  alamatOutlet: null,
  teleponOutlet: null,
  batas: {},
}

function pasangBluetooth(ada: boolean) {
  Object.defineProperty(navigator, 'bluetooth', {
    configurable: true,
    value: ada ? { requestDevice: vi.fn() } : undefined,
  })
}

function pasangSecureContext(aman: boolean) {
  Object.defineProperty(globalThis, 'isSecureContext', { configurable: true, value: aman })
}

function tampilkan() {
  return render(
    <MemoryRouter initialEntries={['/nota/ord-1']}>
      <Routes>
        <Route path="/nota/:id" element={<LayarNota onKeluar={() => {}} />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  // clearAllMocks membersihkan pemanggilan, BUKAN nilai kembaliannya.
  ;(ambilPesanan as Mock).mockResolvedValue(PESANAN)
  ;(ambilSetelan as Mock).mockResolvedValue(SETELAN)
})

afterEach(() => {
  // Vitest di proyek ini tak memakai globals, jadi pembersihan otomatis
  // @testing-library tidak aktif — tanpa ini render menumpuk dan pencarian
  // teks menemukan dua elemen yang sama.
  cleanup()
  pasangSecureContext(true)
})

describe('LayarNota — cetak termal', () => {
  it('menawarkan sambungan printer saat browsernya mampu', async () => {
    pasangBluetooth(true)
    pasangSecureContext(true)

    tampilkan()

    expect(await screen.findByRole('button', { name: /Sambungkan printer/i })).toBeTruthy()
  })

  it('menerangkan syarat HTTPS, bukan menghilang diam-diam', async () => {
    // Inilah yang benar-benar terjadi: jalan mulus di localhost, lalu lenyap
    // tanpa jejak begitu dibuka dari alamat LAN. Yang hilang tanpa keterangan
    // dibaca sebagai aplikasi rusak, bukan sebagai syarat yang belum dipenuhi.
    pasangBluetooth(true)
    pasangSecureContext(false)

    tampilkan()

    expect(await screen.findByText(/https:\/\//)).toBeTruthy()
    expect(screen.queryByRole('button', { name: /Sambungkan printer/i })).toBeNull()
  })

  it('tidak menawarkan apa pun di browser yang tak akan pernah bisa', async () => {
    // Safari & semua browser di iOS. Menyuruhnya "pakai https://" adalah saran
    // yang mustahil dijalankan — jadi tak ada yang ditampilkan sama sekali.
    pasangBluetooth(false)
    pasangSecureContext(true)

    tampilkan()

    // Ditunggu sampai notanya benar-benar tampil, supaya ketiadaan tombolnya
    // bukan sekadar karena layarnya masih memuat.
    expect(await screen.findByText('A-001')).toBeTruthy()
    expect(screen.queryByText(/https:\/\//)).toBeNull()
    expect(screen.queryByRole('button', { name: /Sambungkan printer/i })).toBeNull()
  })

  it('memakai nama kafe dari setelan, bukan dari env', async () => {
    // Satu build melayani banyak kafe; nama yang dipanggang saat build cuma
    // benar untuk kafe pertama.
    pasangBluetooth(false)

    tampilkan()

    expect(await screen.findByText('Kopi Senja')).toBeTruthy()
  })
})
