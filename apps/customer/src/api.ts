/**
 * Satu-satunya pintu keluar app pelanggan ke backend.
 *
 * Alasan dipusatkan: hari ini browser menghadap DUA origin (Ordering 8000,
 * Catalog 8001) karena `showTable` tak mengembalikan menu. Kalau nanti semua
 * pindah ke satu domain lewat Traefik, yang berubah cuma isi .env.local —
 * nol sentuhan ke komponen.
 */

// PERINGATAN: nilai VITE_* ikut terbungkus ke bundle yang dikirim ke HP
// pelanggan. Hanya nilai publik di sini — tak pernah token atau kunci.
const ORDERING = import.meta.env.VITE_ORDERING_URL
const CATALOG = import.meta.env.VITE_CATALOG_URL

/** Nama kafe belum ada di API mana pun (lihat kontrak) — sementara dari env. */
export const NAMA_KAFE = import.meta.env.VITE_NAMA_KAFE || 'Kafe'

export type Meja = {
  tenant_id: string
  outlet_id: string
  table_id: string
  label: string
}

export type Produk = {
  id: string
  nama: string
  deskripsi: string
  harga: number
  gambarUrl: string | null
}

export type Kategori = {
  id: string
  nama: string
  produk: Produk[]
}

/**
 * Batas qty per item — cermin `items.*.qty` integer|min:1|max:99 di
 * StoreOrderRequest. Kalau batas server berubah, ini harus ikut, kalau tidak
 * pelanggan kena 422 setelah capek mengisi keranjang.
 */
export const MAKS_QTY = 99

/** Cermin `items` max:50 di StoreOrderRequest. */
export const MAKS_ITEM = 50

/** Cermin `items.*.note` max:255 di StoreOrderRequest. */
export const MAKS_NOTE = 255

/**
 * Satu baris keranjang.
 *
 * qty dan note tinggal dalam SATU objek, bukan dua peta terpisah yang
 * di-index id produk yang sama. Dua peta harus dijaga sinkron, dan yang
 * pertama kali lupa disinkronkan selalu penghapusan: item dibuang dari
 * keranjang, catatannya tertinggal, lalu menempel diam-diam ke pesanan
 * berikutnya untuk produk yang sama. Satu objek membuat itu mustahil.
 */
export type BarisKeranjang = { qty: number; note: string }
export type Keranjang = Record<string, BarisKeranjang>

async function ambil<T>(url: string): Promise<T> {
  const res = await fetch(url)
  if (!res.ok) {
    // Pesan teknis untuk log; komponen yang menerjemahkannya jadi bahasa manusia.
    throw new Error(`Permintaan gagal (${res.status})`)
  }
  const json = await res.json()
  // Semua endpoint di sistem ini membungkus hasil di {data: ...}.
  return json.data
}

export const ambilMeja = (qrToken: string) =>
  ambil<Meja>(`${ORDERING}/api/t/${encodeURIComponent(qrToken)}`)

/** Bentuk mentah dari Catalog — hanya field yang benar-benar kita pakai. */
export type ProdukMentah = {
  id: string
  name: string
  description: string | null
  price: string | number | null
  image_url: string | null
}
export type KategoriMentah = { id: string; name: string; products: ProdukMentah[] }

/**
 * Ambil menu, lalu SALIN ULANG ke bentuk kita sendiri.
 *
 * Penyalinan ini bukan gaya-gayaan — dua alasan nyata:
 *
 * 1. `/api/menu` mengembalikan model Eloquent MENTAH (tanpa API Resource),
 *    jadi payload-nya membawa tenant_id & timestamps. Begitu F7b menambah
 *    kolom harga modal ke `products`, HPP ikut terkirim ke HP pelanggan.
 *    Dengan daftar-izin eksplisit di sini, kolom baru apa pun TIDAK pernah
 *    masuk ke state app — bocornya berhenti di pintu ini.
 *
 * 2. `price` kolom decimal(12,2) -> dikirim sebagai STRING "22000.00", bukan
 *    angka. Tanpa konversi, `harga * qty` menghasilkan hasil ngawur.
 */
export async function ambilMenu(tenantId: string): Promise<Kategori[]> {
  const mentah = await ambil<KategoriMentah[]>(
    `${CATALOG}/api/menu?tenant=${encodeURIComponent(tenantId)}`,
  )

  return petakanMenu(mentah)
}

/**
 * Bagian murni dari ambilMenu — nol jaringan, jadi bisa diuji langsung.
 * Dipisah justru karena inilah bagian yang bisa salah diam-diam.
 */
export function petakanMenu(mentah: KategoriMentah[]): Kategori[] {
  return mentah
    .map((k) => ({
      id: k.id,
      nama: k.name,
      // Baris rusak DIBUANG, bukan menumbangkan seluruh menu: satu produk
      // dengan harga null lebih baik hilang daripada tampil Rp 0 dan
      // menciptakan pesanan yang salah jumlah uangnya.
      produk: (k.products ?? []).flatMap((p) => {
        // Number(null) dan Number('') dua-duanya menghasilkan 0 — persis
        // jebakan "harga kosong diam-diam jadi Rp 0". Kosong harus jadi NaN
        // supaya penjaga di bawah menangkapnya.
        const teks = typeof p.price === 'string' ? p.price.trim() : p.price
        const harga = teks === null || teks === undefined || teks === '' ? NaN : Number(teks)
        // <= 0 ditolak, bukan cuma < 0: menu seharga Rp 0 hampir pasti data
        // rusak, dan kalau lolos ia membuat pesanan bernilai nol rupiah.
        if (!Number.isFinite(harga) || harga <= 0) return []
        return [
          {
            id: p.id,
            nama: p.name,
            deskripsi: p.description ?? '',
            harga,
            gambarUrl: p.image_url,
          },
        ]
      }),
    }))
    // Kategori yang jadi kosong tak perlu ditampilkan sebagai judul menggantung.
    .filter((k) => k.produk.length > 0)
}

/**
 * Cara bayar yang DIINGINKAN pelanggan. Kosakatanya sengaja sama dengan server
 * supaya tak ada penerjemahan di tengah jalan; layar yang menyebutnya "Tunai"
 * dan "E-Payment".
 *
 * Ini niat, bukan bukti. Yang menentukan pesanan benar-benar lunas tetap kasir.
 */
export type CaraBayar = 'cash' | 'qris_static'

export type PayloadPesanan = {
  qr_token: string
  order_type: 'dine_in'
  customer_name: string
  payment_preference: CaraBayar
  items: Array<{ product_id: string; qty: number; note?: string }>
}

/**
 * Keranjang -> payload server. Murni, nol jaringan, jadi bisa diuji langsung.
 *
 * order_type dikunci 'dine_in': pelanggan sampai ke sini dengan memindai QR
 * yang menempel di meja, jadi konteksnya sudah pasti. Takeaway lewat kasir.
 *
 * Yang SENGAJA tidak dilakukan fungsi ini: memotong keranjang di batas 50 item.
 * Memotong diam-diam berarti pelanggan membayar pesanan yang bukan miliknya —
 * UI yang harus menolak dengan pesan jelas.
 */
export function susunPesanan(args: {
  qrToken: string
  nama: string
  isi: Keranjang
  caraBayar: CaraBayar
}): PayloadPesanan {
  return {
    qr_token: args.qrToken,
    order_type: 'dine_in',
    customer_name: args.nama.trim(),
    payment_preference: args.caraBayar,
    items: Object.entries(args.isi).flatMap(([product_id, baris]) => {
      // floor sebelum bandingkan: 0.5 harus hilang, bukan jadi 1.
      const qty = Math.min(MAKS_QTY, Math.floor(baris.qty))
      if (qty <= 0) return []
      // Dipotong di sini juga, bukan hanya mengandalkan maxLength di input:
      // isi keranjang bisa datang dari localStorage yang diedit orang, dan
      // 256 karakter berarti 422 setelah pelanggan menekan kirim.
      const note = baris.note.trim().slice(0, MAKS_NOTE)
      // Catatan kosong DIHILANGKAN dari payload, bukan dikirim sebagai "":
      // kolomnya nullable, dan "" membuat kasir melihat baris catatan hampa
      // di layar antreannya.
      return [note ? { product_id, qty, note } : { product_id, qty }]
    }),
  }
}

export type Pesanan = {
  id: string
  order_number: string
  status: string
  customer_name: string
  /** Jumlah harga menu sebelum diskon, layanan, dan pajak. */
  gross_subtotal: number
  discount_total: number
  service_charge: number
  tax: number
  /** Yang benar-benar ditagih. Dihitung SERVER, bukan disalin dari client. */
  grand_total: number
  expires_at: string | null
  /**
   * Gambar QRIS kafe, atau null kalau QR tak boleh ditampilkan.
   *
   * Server yang memutuskan, bukan layar: ia mengirim null untuk pesanan yang
   * sudah dibayar, batal, atau kedaluwarsa. Jadi tak ada kondisi status yang
   * perlu — dan bisa terlewat — ditulis ulang di sini.
   */
  qrisImageUrl: string | null
  /**
   * Jam pelanggan melaporkan sudah transfer, atau null kalau belum pernah.
   *
   * Dipakai layar untuk mengganti tombol dengan keterangan — bukan untuk
   * memutuskan boleh-tidaknya melapor. Yang menolak laporan kedua tetap
   * server; layar cuma berhenti menawarkannya.
   */
  claimedAt: string | null
  /** Isi pesanan. Dipakai popup "Pesanan saya" untuk mengingatkan apa yang dipesan. */
  items: BarisPesanan[]
  /**
   * Tiga titik garis kemajuan. Yang datang JAMnya, bukan "sudah/belum": layar
   * menuliskannya di bawah tiap titik, dan null sudah cukup berarti "belum
   * terjadi" tanpa perlu penanda kedua yang bisa berselisih dengannya.
   */
  createdAt: string | null
  paidAt: string | null
  readyAt: string | null
}

export type BarisPesanan = {
  product_id: string
  product_name: string
  qty: number
  line_total: number
  note: string
}

/**
 * Baris pesanan dari server. Data asing, sama seperti harga menu.
 *
 * Baris yang tak terbaca DIBUANG satuan, bukan menumbangkan seluruh pesanan:
 * satu nama produk yang hilang tak boleh membuat pelanggan kehilangan
 * pandangan atas sembilan item lainnya.
 */
export function petakanBarisPesanan(nilai: unknown): BarisPesanan[] {
  if (!Array.isArray(nilai)) return []

  return nilai.flatMap((baris): BarisPesanan[] => {
    if (typeof baris !== 'object' || baris === null) return []

    const m = baris as Record<string, unknown>
    const qty = Number(m.qty)
    if (!Number.isFinite(qty) || qty <= 0) return []

    return [{
      product_id: String(m.product_id ?? ''),
      // Nama kosong lebih baik daripada "undefined" tercetak di layar orang.
      product_name: typeof m.product_name === 'string' ? m.product_name : '',
      qty,
      // NaN dibiarkan lewat: rupiah() sudah menuliskannya "Rp —", dan angka
      // yang dikarang jauh lebih berbahaya daripada tanda tak-terbaca.
      line_total: Number(m.line_total),
      note: typeof m.note === 'string' ? m.note : '',
    }]
  })
}

/**
 * Alamat QRIS dari blok `payment`. Diperlakukan sebagai data asing.
 *
 * Dua hal yang dijaga: respons server versi lama belum punya blok `payment`
 * sama sekali (halaman status tak boleh tumbang gara-gara itu), dan nilainya
 * berakhir di `<img src>` — string kosong menghasilkan permintaan balik ke
 * alamat halaman itu sendiri, yang muncul sebagai gambar rusak.
 *
 * Diekspor untuk diuji langsung, alasan yang sama seperti petakanMenu: inilah
 * bagian yang bisa salah tanpa bersuara.
 */
export function bacaQris(payment: unknown): string | null {
  if (typeof payment !== 'object' || payment === null) return null
  const nilai = (payment as Record<string, unknown>).qris_image_url
  if (typeof nilai !== 'string' || nilai.trim() === '') return null

  const alamat = nilai.trim()

  // Gambar hasil unggahan owner disimpan Ordering dan dikirim sebagai path
  // relatif (`/storage/qris/...`) — relatif terhadap ORDERING, bukan terhadap
  // app ini. Tanpa awalan ini browser mencarinya di alamat app pelanggan, tak
  // menemukannya, dan blok pembayaran menghilang seolah QRIS belum dipasang.
  //
  // Alamat lengkap (http/https) dibiarkan apa adanya: owner boleh menaruh
  // gambarnya di tempat lain, dan menempeli awalan pada URL yang sudah utuh
  // justru merusaknya.
  return alamat.startsWith('/') ? `${ORDERING}${alamat}` : alamat
}

/**
 * Jam klaim dari blok `payment`. Data asing, diperlakukan sama seperti bacaQris:
 * respons lama belum punya blok itu, dan nilainya berakhir di `new Date()` yang
 * mengubah sampah jadi "Invalid Date" tanpa bersuara.
 */
export function bacaWaktuKlaim(payment: unknown): string | null {
  if (typeof payment !== 'object' || payment === null) return null

  const nilai = (payment as Record<string, unknown>).claimed_at
  if (typeof nilai !== 'string' || nilai.trim() === '') return null

  return nilai
}

/** Sama seperti harga menu: semua nilai uang datang sebagai string decimal. */
function petakanPesanan(m: Record<string, unknown>): Pesanan {
  return {
    id: String(m.id),
    order_number: String(m.order_number),
    status: String(m.status),
    customer_name: String(m.customer_name),
    gross_subtotal: Number(m.gross_subtotal),
    discount_total: Number(m.discount_total),
    service_charge: Number(m.service_charge),
    tax: Number(m.tax),
    grand_total: Number(m.grand_total),
    expires_at: m.expires_at ? String(m.expires_at) : null,
    qrisImageUrl: bacaQris(m.payment),
    claimedAt: bacaWaktuKlaim(m.payment),
    items: petakanBarisPesanan(m.items),
    createdAt: m.created_at ? String(m.created_at) : null,
    paidAt: m.paid_at ? String(m.paid_at) : null,
    readyAt: m.ready_at ? String(m.ready_at) : null,
  }
}

export async function kirimPesanan(payload: PayloadPesanan): Promise<Pesanan> {
  const res = await fetch(`${ORDERING}/api/orders`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })

  if (!res.ok) {
    // Pesan dibedakan karena tindakan pelanggannya beda: 429 = tunggu sebentar,
    // 422 = ada yang salah dengan pesanannya, sisanya = masalah di kami.
    if (res.status === 429) throw new Error('Terlalu sering mencoba. Tunggu sebentar, lalu coba lagi.')
    if (res.status === 422) throw new Error('Pesanan ditolak. Coba periksa lagi isinya.')
    if (res.status === 404) throw new Error('QR meja tidak dikenali. Scan ulang QR di meja.')
    throw new Error('Pesanan gagal dikirim. Coba lagi sebentar.')
  }

  const json = await res.json()
  return petakanPesanan(json.data)
}

/**
 * Laporkan "sudah transfer". Balasannya pesanan yang sudah diperbarui, jadi
 * layar tak perlu menunggu putaran polling berikutnya untuk berubah.
 *
 * 409 bukan kegagalan teknis melainkan kabar: pesanannya sudah dibayar, batal,
 * atau hangus sementara jari pelanggan menuju tombol. Karena itu pesannya
 * menyuruh melihat status di atas, bukan menyuruh mencoba lagi.
 */
export async function klaimSudahBayar(id: string): Promise<Pesanan> {
  const res = await fetch(`${ORDERING}/api/orders/${id}/claim-paid`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })

  if (!res.ok) {
    if (res.status === 409) throw new Error('Pesanan ini sudah tidak menunggu pembayaran. Lihat status di atas.')
    if (res.status === 429) throw new Error('Terlalu sering menekan. Tunggu sebentar, lalu coba lagi.')
    if (res.status === 404) throw new Error('Pesanan tidak ditemukan. Tanya kasir ya.')
    throw new Error('Gagal mengirim laporan. Coba lagi sebentar.')
  }

  const json = await res.json()
  return petakanPesanan(json.data)
}

export async function ambilPesanan(id: string): Promise<Pesanan> {
  return petakanPesanan(await ambil<Record<string, unknown>>(`${ORDERING}/api/orders/${id}`))
}
