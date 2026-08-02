// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cetak, didukung, halangan, lupakanPrinter, sambung, tersambung } from './printerBluetooth'

type Ciri = {
  properties: { write: boolean; writeWithoutResponse: boolean }
  writeValue: ReturnType<typeof vi.fn>
  writeValueWithoutResponse?: ReturnType<typeof vi.fn>
}

function ciri(sifat: Partial<Ciri['properties']>): Ciri {
  return {
    properties: { write: false, writeWithoutResponse: false, ...sifat },
    writeValue: vi.fn(async () => {}),
    writeValueWithoutResponse: vi.fn(async () => {}),
  }
}

/** Printer palsu. `daftar` = characteristic yang ditemukan, berurutan. */
function printerPalsu(daftar: Ciri[]) {
  const gatt = {
    connected: false,
    connect: vi.fn(async () => {
      gatt.connected = true

      return { getPrimaryServices: async () => [{ getCharacteristics: async () => daftar }] }
    }),
    disconnect: vi.fn(() => {
      gatt.connected = false
    }),
  }

  return { name: 'MTP-II', gatt }
}

function pasangBluetooth(perangkat: unknown | null) {
  Object.defineProperty(navigator, 'bluetooth', {
    configurable: true,
    value: perangkat === null ? undefined : { requestDevice: vi.fn(async () => perangkat) },
  })
}

function pasangSecureContext(aman: boolean) {
  Object.defineProperty(globalThis, 'isSecureContext', { configurable: true, value: aman })
}

beforeEach(() => {
  lupakanPrinter()
  pasangSecureContext(true)
})

afterEach(() => {
  lupakanPrinter()
})

describe('didukung', () => {
  it('false saat browser tak punya Web Bluetooth', () => {
    // Safari & semua browser di iOS. Tombolnya tak boleh muncul sama sekali.
    pasangBluetooth(null)

    expect(didukung()).toBe(false)
  })

  it('false saat halaman bukan secure context', () => {
    // Inilah kasus yang paling mungkin terjadi di kafe: kasir membuka layar
    // ini dari HP lewat http://192.168.x.x. navigator.bluetooth ADA di Chrome
    // Android, tapi tak akan pernah mengizinkan apa pun tanpa HTTPS.
    pasangBluetooth(printerPalsu([ciri({ writeWithoutResponse: true })]))
    pasangSecureContext(false)

    expect(didukung()).toBe(false)
  })

  it('true di Chrome atas HTTPS', () => {
    pasangBluetooth(printerPalsu([ciri({ writeWithoutResponse: true })]))

    expect(didukung()).toBe(true)
  })
})

describe('halangan', () => {
  it('membedakan browser yang tak bisa dari halaman yang belum HTTPS', () => {
    // Bedanya menentukan apa yang ditampilkan ke orangnya: yang satu tak bisa
    // diapa-apakan siapa pun, yang satu lagi tinggal dibuka lewat https://.
    // Kalau keduanya diperlakukan sama, kasir yang membuka alamat LAN melihat
    // layar kosong tanpa satu pun petunjuk.
    pasangBluetooth(null)
    expect(halangan()).toBe('tidak-didukung')

    pasangBluetooth(printerPalsu([ciri({ writeWithoutResponse: true })]))
    pasangSecureContext(false)
    expect(halangan()).toBe('butuh-https')

    pasangSecureContext(true)
    expect(halangan()).toBeNull()
  })

  it('browser tanpa Web Bluetooth tetap tidak-didukung walau halamannya aman', () => {
    // Urutan pemeriksaan penting: iPhone atas HTTPS tetap tak punya jalannya,
    // dan menyuruhnya "pakai https://" adalah saran yang mustahil dijalankan.
    pasangBluetooth(null)
    pasangSecureContext(true)

    expect(halangan()).toBe('tidak-didukung')
  })
})

describe('cetak', () => {
  it('memotong kiriman jadi paket kecil', async () => {
    // Satu struk utuh sekaligus membuat printer berhenti di tengah — dan yang
    // keluar adalah setengah struk tanpa baris TOTAL.
    const tulis = ciri({ writeWithoutResponse: true })
    pasangBluetooth(printerPalsu([tulis]))

    await sambung()
    await cetak(new Uint8Array(45))

    const kirim = tulis.writeValueWithoutResponse!

    expect(kirim).toHaveBeenCalledTimes(3)
    expect(kirim.mock.calls[0][0]).toHaveLength(20)
    expect(kirim.mock.calls[1][0]).toHaveLength(20)
    expect(kirim.mock.calls[2][0]).toHaveLength(5)
  })

  it('tidak memilih characteristic pertama yang kebetulan writable', async () => {
    // Bentuk kegagalan paling menyebalkan: layar bilang "tersambung", kertas
    // tak pernah keluar, dan tak ada satu pun pesan error — karena yang
    // ditulisi ternyata characteristic konfigurasi.
    const konfigurasi = ciri({ write: true })
    const printer = ciri({ writeWithoutResponse: true })
    pasangBluetooth(printerPalsu([konfigurasi, printer]))

    await sambung()
    await cetak(new Uint8Array(4))

    expect(konfigurasi.writeValue).not.toHaveBeenCalled()
    expect(printer.writeValueWithoutResponse).toHaveBeenCalled()
  })

  it('memakai writeValue biasa saat printer tak punya yang tanpa-jawaban', async () => {
    const tulis = ciri({ write: true })
    pasangBluetooth(printerPalsu([tulis]))

    await sambung()
    await cetak(new Uint8Array(4))

    expect(tulis.writeValue).toHaveBeenCalledTimes(1)
  })

  it('menyambung ulang sendiri saat printer sempat tidur', async () => {
    // Printer termal memutus GATT setelah beberapa menit nganggur, tanpa
    // memberi tahu siapa pun. Tanpa ini, cetakan kedua gagal padahal kasir tak
    // melakukan apa pun yang salah — dan halamannya tak pernah di-reload.
    const tulis = ciri({ writeWithoutResponse: true })
    const alat = printerPalsu([tulis])
    pasangBluetooth(alat)

    await sambung()
    await cetak(new Uint8Array(4))

    alat.gatt.connected = false

    await cetak(new Uint8Array(4))

    expect(alat.gatt.connect).toHaveBeenCalledTimes(2)
    expect(tulis.writeValueWithoutResponse).toHaveBeenCalledTimes(2)
  })

  it('menolak mencetak sebelum printer dipilih', async () => {
    pasangBluetooth(printerPalsu([ciri({ writeWithoutResponse: true })]))

    await expect(cetak(new Uint8Array(4))).rejects.toThrow(/belum dipilih/)
  })
})

describe('sambung', () => {
  it('tidak mengaku tersambung saat perangkatnya bukan printer', async () => {
    // Kasir bisa saja memilih headset dari daftar. Menyimpannya sebagai
    // "printer" berarti tombol cetak menyala untuk perangkat yang tak akan
    // pernah mengeluarkan kertas.
    pasangBluetooth(printerPalsu([ciri({})]))

    await expect(sambung()).rejects.toThrow(/bukan printer/)
    expect(tersambung()).toBe(false)
  })

  it('menolak dengan pesan jelas saat browsernya memang tak bisa', async () => {
    pasangBluetooth(null)

    await expect(sambung()).rejects.toThrow(/tidak mendukung Bluetooth/)
  })
})
