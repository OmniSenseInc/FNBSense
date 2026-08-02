/**
 * Kirim byte ESC/POS ke printer termal lewat Web Bluetooth.
 *
 * Lapisan ini SENGAJA setipis mungkin, justru karena ia satu-satunya bagian
 * yang tak bisa dibuktikan tanpa printer di atas meja. Semua keputusan yang
 * bisa salah — angka mana yang dicetak, lebar baris, karakter yang tak
 * terwakili — sudah selesai di struk.ts dan terkunci di sana oleh test. Yang
 * tersisa di sini cuma memindahkan byte.
 *
 * Tak ada cetak ulang otomatis. Printer tak pernah memberi tahu berapa byte
 * yang sudah benar-benar tercetak, jadi mengulang setelah kegagalan di tengah
 * berarti mempertaruhkan dua struk untuk satu pesanan. Yang gagal dilaporkan
 * ke kasir, dan kasir yang memutuskan.
 */

// Potongan Web Bluetooth yang benar-benar dipakai, ditulis sendiri alih-alih
// menarik @types/web-bluetooth: yang dibutuhkan cuma sebanyak ini, dan
// dependensi baru harus dibayar setiap kali orang lain menyiapkan proyek ini.
type Karakteristik = {
  properties: { write: boolean; writeWithoutResponse: boolean }
  writeValueWithoutResponse?: (nilai: BufferSource) => Promise<void>
  writeValue: (nilai: BufferSource) => Promise<void>
}

type Layanan = { getCharacteristics: () => Promise<Karakteristik[]> }

type Gatt = {
  connected: boolean
  connect: () => Promise<{ getPrimaryServices: () => Promise<Layanan[]> }>
  disconnect: () => void
}

type Perangkat = { name?: string | null; gatt?: Gatt }

type Bluetooth = {
  requestDevice: (opsi: {
    filters?: { services: unknown[] }[]
    acceptAllDevices?: boolean
    optionalServices?: unknown[]
  }) => Promise<Perangkat>
}

/**
 * Service GATT yang lazim dipakai printer termal murah.
 *
 * Tak ada standar "printer" di Bluetooth LE — tiap pabrik memakai UUID
 * sendiri, dan daftar ini hasil pengamatan lapangan, bukan spesifikasi. Itu
 * sebabnya harus selalu ada jalan keluar lewat sambung(true).
 */
const SERVICE_PRINTER = [0x18f0, 0xff00, 0xffe0, 0xffe5, 0xff90]

/**
 * Ukuran potongan tiap tulis.
 *
 * BLE membawa muatan kecil per paket. Mengirim satu struk utuh sekaligus
 * membuat printer berhenti di tengah — dan yang keluar adalah setengah struk
 * tanpa TOTAL, yaitu bentuk kegagalan yang paling tak terlihat.
 */
const POTONG = 20

/** Jeda antar potongan. Penyangga printer termal murah lebih lambat dari BLE. */
const JEDA_MS = 50

let perangkat: Perangkat | null = null
let tulis: Karakteristik | null = null

function bluetooth(): Bluetooth | null {
  const nav = navigator as unknown as { bluetooth?: Bluetooth }

  return nav.bluetooth ?? null
}

/**
 * Kenapa jalur ini tak bisa dipakai di sini. null = bisa.
 *
 * Dua sebabnya dibedakan karena yang bisa dilakukan orangnya berbeda jauh:
 *
 * - 'tidak-didukung' — browsernya memang tak punya Web Bluetooth (Safari, dan
 *   SEMUA browser di iOS karena mesinnya sama). Tak ada yang bisa dilakukan
 *   siapa pun, jadi layarnya tak menawarkan apa-apa.
 * - 'butuh-https' — browsernya bisa, halamannya yang belum aman. Ini justru
 *   kasus yang paling sering terjadi: `http://localhost` dihitung aman
 *   sehingga jalan mulus di laptop, lalu alamat LAN atau domain http:// yang
 *   sama persis diam-diam mematikan fiturnya. Tanpa pesan, orang menyimpulkan
 *   aplikasinya rusak.
 */
export type Halangan = 'tidak-didukung' | 'butuh-https'

export function halangan(): Halangan | null {
  if (bluetooth() === null) return 'tidak-didukung'
  if (globalThis.isSecureContext !== true) return 'butuh-https'

  return null
}

export function didukung(): boolean {
  return halangan() === null
}

export function tersambung(): boolean {
  return tulis !== null && perangkat?.gatt?.connected === true
}

/** Nama printer yang sedang dipegang, untuk ditampilkan di layar. */
export function namaPrinter(): string | null {
  return perangkat?.name ?? null
}

/**
 * Minta kasir memilih printer. WAJIB dipanggil dari dalam penanganan klik —
 * browser menolak dialog perangkat yang tak dipicu manusia.
 *
 * `semua` membuka daftar tanpa saringan. Filter di atas menyembunyikan printer
 * yang UUID service-nya tak ada di daftar kita, dan printer yang tak muncul
 * sama sekali adalah jalan buntu yang tak bisa diselesaikan kasir sendiri.
 */
export async function sambung(semua = false): Promise<void> {
  const ble = bluetooth()

  if (ble === null) {
    throw new Error('Browser ini tidak mendukung Bluetooth. Pakai Chrome di Android atau laptop.')
  }

  const alat = await ble.requestDevice(
    semua
      ? { acceptAllDevices: true, optionalServices: SERVICE_PRINTER }
      : {
          filters: SERVICE_PRINTER.map((s) => ({ services: [s] })),
          optionalServices: SERVICE_PRINTER,
        },
  )

  // Baru dipegang setelah sambungannya terbukti. Kalau ditugaskan lebih dulu
  // dan hubungkan() gagal, layar berkata "tersambung ke printer" untuk
  // perangkat yang tak pernah menjawab.
  tulis = await hubungkan(alat)
  perangkat = alat
}

export function putus(): void {
  perangkat?.gatt?.disconnect()
  perangkat = null
  tulis = null
}

/**
 * Cetak. Menyambung ulang sendiri kalau koneksinya sudah putus.
 *
 * Printer termal tidur setelah beberapa menit nganggur dan memutus GATT-nya
 * tanpa memberi tahu siapa pun. Tanpa penyambungan ulang di sini, cetakan
 * berikutnya gagal padahal kasir tak melakukan apa pun yang salah. Menyambung
 * ke perangkat yang sudah pernah diizinkan tidak memunculkan dialog dan tidak
 * menuntut gerakan pengguna, jadi kasir tak melihat apa-apa — kertas keluar.
 */
export async function cetak(bytes: Uint8Array): Promise<void> {
  const alat = perangkat

  if (alat === null) {
    throw new Error('Printer belum dipilih.')
  }

  if (!tersambung()) {
    tulis = await hubungkan(alat)
  }

  const target = tulis

  if (target === null) {
    throw new Error('Printer tersambung tapi tidak menerima data.')
  }

  for (let i = 0; i < bytes.length; i += POTONG) {
    const potongan = bytes.slice(i, i + POTONG)

    // writeValueWithoutResponse lebih cepat dan itu yang didukung sebagian
    // besar printer termal; sebagian lain hanya punya write biasa.
    if (target.properties.writeWithoutResponse && target.writeValueWithoutResponse) {
      await target.writeValueWithoutResponse(potongan)
    } else {
      await target.writeValue(potongan)
    }

    await new Promise((lanjut) => setTimeout(lanjut, JEDA_MS))
  }
}

/**
 * Sambung GATT dan temukan characteristic yang bisa ditulisi.
 *
 * Yang mendukung tulis-tanpa-jawaban DIDAHULUKAN, dan itu bukan soal
 * kecepatan: mengambil begitu saja characteristic writable pertama yang
 * ditemukan adalah cara paling umum mendapat "printer tersambung tapi tak
 * mencetak" — yang tertulis ternyata characteristic konfigurasi, dan tak ada
 * satu pun pesan error yang muncul.
 */
async function hubungkan(alat: Perangkat): Promise<Karakteristik> {
  if (!alat.gatt) {
    throw new Error('Perangkat ini tidak bisa dihubungi.')
  }

  const server = await alat.gatt.connect()
  const layanan = await server.getPrimaryServices()

  let cadangan: Karakteristik | null = null

  for (const satu of layanan) {
    for (const ciri of await satu.getCharacteristics()) {
      if (ciri.properties.writeWithoutResponse) {
        return ciri
      }

      if (ciri.properties.write && cadangan === null) {
        cadangan = ciri
      }
    }
  }

  if (cadangan === null) {
    throw new Error('Perangkat ini bukan printer — tak ada jalur tulis yang ditemukan.')
  }

  return cadangan
}

/** Hanya untuk test — melepas perangkat yang tersimpan di modul. */
export function lupakanPrinter(): void {
  perangkat = null
  tulis = null
}
