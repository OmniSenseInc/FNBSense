/**
 * Satu-satunya pintu keluar app kasir ke backend.
 *
 * Dipusatkan dengan alasan yang sama seperti apps/customer: hari ini browser
 * menghadap dua service (IAM untuk login, Ordering untuk antrean), dan kalau
 * nanti semuanya pindah ke satu domain lewat Traefik yang berubah cuma .env.
 *
 * Bedanya dengan app pelanggan: SEMUA permintaan di sini membawa token, dan
 * token itu berumur 15 menit sementara satu shift kasir berjam-jam. Jadi
 * penukaran token bukan fitur tambahan — tanpa itu kasir tertendang ke layar
 * login tiap seperempat jam.
 */

// PERINGATAN: nilai VITE_* ikut terbungkus ke bundle. Hanya nilai publik.
const IAM = import.meta.env.VITE_IAM_URL
const ORDERING = import.meta.env.VITE_ORDERING_URL
const NOTIFICATION = import.meta.env.VITE_NOTIFICATION_URL
const INVENTORY = import.meta.env.VITE_INVENTORY_URL
const FINANCE = import.meta.env.VITE_FINANCE_URL
const CATALOG = import.meta.env.VITE_CATALOG_URL

/**
 * Token disimpan di localStorage, bukan memori, supaya kasir yang tak sengaja
 * me-refresh tab tak kehilangan sesinya di tengah antrean.
 *
 * ponytail: localStorage bisa dibaca skrip apa pun yang berhasil masuk ke
 * halaman ini (XSS). Diterima untuk sekarang karena app ini nol dependensi
 * pihak ketiga di runtime dan nol konten dari user lain. Kalau nanti ada
 * embed/iklan/rich text, pindahkan ke cookie httpOnly + endpoint sesi.
 */
const KUNCI_TOKEN = 'fnb.staff.token'
/** Nama kasir (dari respons login) — dipakai header supaya kasir tahu ia login di akun siapa. */
const KUNCI_NAMA = 'fnb.staff.nama'

/**
 * Penanda "token sudah tak bisa diselamatkan" — App menangkapnya dan kembali
 * ke layar login. Dibedakan dari galat biasa supaya kegagalan jaringan sesaat
 * TIDAK melempar kasir keluar; itu cuma perlu dicoba lagi.
 */
export const SESI_HABIS = 'SESI_HABIS'

/**
 * Cermin middleware `role:cashier,owner` di services/ordering/routes/api.php.
 * Ditolak di sini juga supaya staf dapur yang salah buka app melihat kalimat
 * jelas, bukan antrean kosong dengan galat 403 yang tak dijelaskan siapa pun.
 */
const PERAN_BOLEH = ['cashier', 'owner']

/** Cermin App\Enums\PaymentMethod di Ordering. */
export type CaraBayar = 'qris_static' | 'cash'

export const bacaToken = (): string | null => localStorage.getItem(KUNCI_TOKEN)
const simpanToken = (token: string) => localStorage.setItem(KUNCI_TOKEN, token)
const simpanNama = (nama: string) => localStorage.setItem(KUNCI_NAMA, nama)
export const bacaNama = (): string | null => localStorage.getItem(KUNCI_NAMA)
export const hapusToken = () => {
  localStorage.removeItem(KUNCI_TOKEN)
  localStorage.removeItem(KUNCI_NAMA)
}

async function jsonDari(res: Response): Promise<Record<string, unknown>> {
  try {
    return await res.json()
  } catch {
    // Respons kosong/bukan JSON (proxy mati, halaman galat HTML) tak boleh
    // meledak sebagai SyntaxError yang tak berarti apa-apa bagi kasir.
    return {}
  }
}

/**
 * Login kasir. Menyimpan token kalau berhasil, melempar pesan siap-tampil
 * kalau tidak.
 */
export async function login(email: string, password: string): Promise<void> {
  const res = await fetch(`${IAM}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }),
  })

  if (res.status === 401) throw new Error('Email atau password salah.')
  // IAM membatasi percobaan login; tanpa pesan khusus kasir mengira password-nya
  // yang salah dan terus mencoba, yang justru memperpanjang blokirnya.
  if (res.status === 429) throw new Error('Terlalu sering mencoba. Tunggu semenit, lalu coba lagi.')
  if (!res.ok) throw new Error('Login gagal. Coba lagi sebentar.')

  const json = await jsonDari(res)
  const token = json.access_token
  const user = json.user as Record<string, unknown> | undefined
  const peran = user?.role
  const nama = user?.name

  if (typeof token !== 'string' || token === '') {
    throw new Error('Login gagal. Coba lagi sebentar.')
  }
  if (typeof peran !== 'string' || !PERAN_BOLEH.includes(peran)) {
    throw new Error('Akun ini tidak punya akses kasir. Minta owner mengubah perannya.')
  }

  simpanToken(token)
  // Nama dipakai header supaya kasir tahu ia login di akun siapa. Kosong bila
  // respons tak menyertakannya (defensif) — header cukup menampilkan FNBSense.
  if (typeof nama === 'string' && nama.trim() !== '') simpanNama(nama.trim())
  else localStorage.removeItem(KUNCI_NAMA)
}

export async function logout(): Promise<void> {
  const token = bacaToken()
  // Token dihapus dari perangkat ini LEBIH DULU dan tanpa syarat: kalau
  // jaringan mati, kasir tetap harus bisa keluar dari layarnya.
  hapusToken()
  if (!token) return
  try {
    await fetch(`${IAM}/api/auth/logout`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
  } catch {
    // Server tak bisa dihubungi -> token tetap sah sampai kedaluwarsa sendiri.
    // Ini konsekuensi yang sudah diketahui (lihat SECURITY_TODO revocation),
    // bukan sesuatu yang bisa diperbaiki dari sisi layar.
  }
}

/**
 * Akun pemilik token masih hidup? — pertanyaan yang cuma bisa dijawab IAM.
 *
 * Token JWT itu "stateless": service lain (ordering, inventory, dst) cuma
 * memverifikasi klaim di dalamnya, tak pernah mengecek akun ke database. Kasir
 * yang akunnya dihapus owner karena itu tetap "dianggap hidup" sampai tokennya
 * kedaluwarsa (15 menit) — layarnya terus jalan tanpa tahu apa-apa.
 *
 * `/api/auth/me` di IAM memuat akun DARI DATABASE (SoftDeletes: akun yang
 * dihapus tak terlihat) → kalau mati balas 403. Layout memanggil ini berkala:
 * 403 = akun sudah dihapus/dinonaktifkan → keluar SEKARANG, bukan menunggu
 * kedaluwarsa. 401 = token kedaluwarsa (urusannya refresh, bukan keluar), dan
 * jaringan gagal bukan salah akun.
 */
export async function cekAkunAktif(): Promise<boolean> {
  const token = bacaToken()
  if (!token) return false

  try {
    const res = await fetch(`${IAM}/api/auth/me`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })

    return res.status !== 403
  } catch {
    // IAM tak terjangkau — jangan salahkan akunnya.
    return true
  }
}

/**
 * Satu penukaran token yang sedang berjalan, dipakai bersama.
 *
 * Tanpa ini: polling antrean dan klik konfirmasi bisa sama-sama kena 401 lalu
 * sama-sama menukar token. Penukaran memasukkan token lama ke blacklist, jadi
 * yang kedua menukar token yang SUDAH mati -> gagal -> kasir tertendang keluar
 * padahal sesinya sehat.
 */
let sedangTukar: Promise<string | null> | null = null

function tukarToken(token: string): Promise<string | null> {
  if (!sedangTukar) {
    sedangTukar = mintaTokenBaru(token)
    // mintaTokenBaru tak pernah reject (gagal = null), jadi finally aman.
    void sedangTukar.finally(() => {
      sedangTukar = null
    })
  }
  return sedangTukar
}

async function mintaTokenBaru(token: string): Promise<string | null> {
  try {
    const res = await fetch(`${IAM}/api/auth/refresh`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    if (!res.ok) return null
    const json = await jsonDari(res)
    return typeof json.access_token === 'string' && json.access_token !== ''
      ? json.access_token
      : null
  } catch {
    return null
  }
}

/**
 * Permintaan ber-token, dengan satu kali penukaran token saat 401.
 *
 * Dibungkus di satu tempat justru supaya tak ada pemanggil yang lupa: setiap
 * endpoint kasir bisa kena 401 kapan saja, dan yang lupa menanganinya akan
 * terlihat sebagai "layar tiba-tiba kosong" di tengah jam sibuk.
 *
 * `basis` ada karena kasir kini bicara ke DUA service ber-token: Ordering dan
 * Notification. Sengaja parameter, bukan fungsi kedua — fungsi kedua berarti
 * menyalin logika tukar-token, dan salinan yang tertinggal versi adalah cara
 * paling rapi kehilangan sesi kasir di satu layar saja.
 */
async function mintaJson(
  jalur: string,
  init: RequestInit | undefined,
  basis: string,
): Promise<Record<string, unknown>> {
  const token = bacaToken()
  if (!token) throw new Error(SESI_HABIS)

  const kirim = (t: string) =>
    fetch(`${basis}${jalur}`, {
      ...init,
      headers: {
        ...init?.headers,
        Authorization: `Bearer ${t}`,
        Accept: 'application/json',
      },
    })

  let res = await kirim(token)

  if (res.status === 401) {
    const baru = await tukarToken(token)
    if (baru === null) {
      hapusToken()
      throw new Error(SESI_HABIS)
    }
    simpanToken(baru)
    res = await kirim(baru)
    // Masih 401 dengan token yang baru saja diterbitkan = akunnya sendiri yang
    // ditolak (dinonaktifkan / outlet dicabut), bukan token kedaluwarsa.
    if (res.status === 401) {
      hapusToken()
      throw new Error(SESI_HABIS)
    }
  }

  // 403 = token sah tapi perannya tidak boleh. Menukar token tak akan menolong,
  // jadi jangan diperlakukan sebagai sesi habis (kasir akan login berulang kali
  // tanpa pernah berhasil) — beri kalimat yang benar.
  if (res.status === 403) throw new Error('Akun ini tidak punya akses ke antrean outlet ini.')
  // 422 = server MENOLAK isinya, bukan gagal dihubungi. Sebelum ini ia jatuh ke
  // "Gagal menghubungi server. Coba lagi." — kalimat yang menyuruh owner
  // mengulangi persis hal yang baru saja ditolak, selamanya.
  if (res.status === 422) {
    const json = await jsonDari(res)
    throw new Error(
      typeof json.message === 'string' && json.message !== ''
        ? json.message
        : 'Ada isian yang ditolak server. Periksa lagi angkanya.',
    )
  }
  // 409 = bentrok keadaan, dan keadaan yang bentrok belum tentu pesanan: shift
  // yang sudah terbuka, shift yang sudah ditutup. Kalimat server dipakai apa
  // adanya karena SEMUA 409 di sistem ini ditulis tangan dalam bahasa manusia
  // (dicek: CashierOrderController, OrderController, ShiftController) — beda
  // dari 404 yang datang dari `firstOrFail` dan berbunyi "No query results for
  // model [...]", kalimat yang tak menolong siapa pun di balik meja kasir.
  if (res.status === 409) {
    const json = await jsonDari(res)
    throw new Error(
      typeof json.message === 'string' && json.message !== ''
        ? json.message
        : 'Sudah tidak bisa diproses. Muat ulang layarnya.',
    )
  }
  if (res.status === 404) throw new Error('Pesanan tidak ditemukan di outlet ini.')
  if (!res.ok) throw new Error('Gagal menghubungi server. Coba lagi.')

  return await jsonDari(res)
}

/**
 * Sebagian besar endpoint membungkus isinya di `data`. Yang tidak — misal
 * `unread-count` yang membalas `{unread: N}` — memakai mintaJson() langsung.
 */
async function panggil<T>(jalur: string, init?: RequestInit, basis: string = ORDERING): Promise<T> {
  return (await mintaJson(jalur, init, basis)).data as T
}

/**
 * Nilai uang dari server -> angka, dengan kosong dipaksa NaN.
 *
 * `Number(null)` dan `Number('')` dua-duanya menghasilkan 0 — persis jebakan
 * "harga hilang diam-diam jadi nol". Di layar kasir akibatnya lebih tajam
 * daripada di menu: nol rupiah bukan cuma tampilan salah, tapi pembayaran yang
 * diterima dengan jumlah salah.
 */
function keAngka(nilai: unknown): number {
  const teks = typeof nilai === 'string' ? nilai.trim() : nilai
  if (teks === null || teks === undefined || teks === '') return NaN
  const angka = Number(teks)
  return Number.isFinite(angka) ? angka : NaN
}

export type ItemPesanan = {
  nama: string
  qty: number
  /** Harga satu porsi, di-snapshot server saat pesanan dibuat. */
  hargaSatuan: number
  total: number
  note: string
}

export type Pesanan = {
  id: string
  order_number: string
  customer_name: string
  /** Yang harus diterima kasir. Dihitung server, tak pernah dari layar ini. */
  grand_total: number
  /** Rincian untuk nota. Semua angka datang jadi dari server — layar tak menghitung. */
  subtotal: number
  /**
   * Subtotal KOTOR: jumlah semua item, sebelum potongan promo.
   *
   * Disimpan server terpisah dari `subtotal` (yang sudah bersih setelah
   * potongan). Tanpa kolom ini nota tak bisa menunjukkan "berapa sebelum
   * diskon" — dan kasir yang menjumlahkan item di kepalanya akan melihat
   * angkanya tak cocok dengan subtotal yang tertera.
   */
  grossSubtotal: number
  /** Potongan promo yang sudah diterapkan server. 0 = tak ada promo. */
  diskon: number
  /** Nama promo yang terpasang (mis. "Diskon 10%"), atau null kalau tak ada. */
  promo: string | null
  layanan: number
  pajak: number
  /** Terisi setelah kasir mengonfirmasi; inilah yang masuk laporan. */
  caraBayar: string | null
  /** Niat pelanggan saat memesan. Petunjuk, bukan keputusan. */
  niatBayar: string | null
  /**
   * Nama meja seperti yang ditulis owner, mis. "Meja 4". null untuk takeaway
   * dan untuk meja yang sudah dihapus — server tak menebak, layar yang memutus
   * apa yang ditulis.
   */
  meja: string | null
  /** `dine_in` | `takeaway` apa adanya dari server. */
  tipe: string | null
  waktuBayar: string | null
  /**
   * Jam pelanggan menekan "Saya sudah bayar" di HP-nya.
   *
   * Klaim, BUKAN bukti. Server tak pernah menjadikannya syarat konfirmasi —
   * pembayar tunai takkan pernah menekannya, dan menjadikannya gerbang berarti
   * pelanggan yang memutuskan kapan kasir boleh menerima uang.
   */
  klaimBayar: string | null
  /** Jam pesanan dinyatakan selesai dibuat. null = masih di dapur. */
  siapPada: string | null
  created_at: string | null
  /**
   * Batas waktu pesanan ini disapu jadi EXPIRED oleh `orders:expire`.
   *
   * Dibaca dari server, TIDAK dihitung ulang dari created_at: batasnya berasal
   * dari `order_expiry_minutes` milik outlet yang bisa diubah owner kapan saja,
   * dan nanti tombol "sudah bayar" pelanggan akan memperpanjangnya sekali.
   * Layar yang menghitung sendiri akan menampilkan tenggat yang berbeda dari
   * tenggat yang benar-benar dipakai server.
   */
  expires_at: string | null
  items: ItemPesanan[]
}

/** Nama promo dari snapshot server, atau null kalau tak ada/bukan objek. */
function namaPromo(p: unknown): string | null {
  if (p !== null && typeof p === 'object') {
    const nama = (p as Record<string, unknown>).name
    if (typeof nama === 'string' && nama !== '') return nama
  }
  return null
}

/**
 * Bentuk mentah Ordering -> bentuk kita. Diekspor untuk diuji langsung: sama
 * seperti di app pelanggan, inilah bagian yang bisa salah tanpa bersuara.
 *
 * `grand_total` di-cast integer oleh Eloquent hari ini, tapi kolomnya
 * decimal(12,2) — satu perubahan cast di server dan angkanya datang sebagai
 * string "38500.00". Number() di sini menjaga itu, dan nilai yang tak masuk
 * akal ditandai NaN supaya layar menolak menampilkan angka bohong.
 */
export function petakanPesanan(m: Record<string, unknown>): Pesanan {
  const total = keAngka(m.grand_total)

  return {
    id: String(m.id),
    order_number: String(m.order_number),
    customer_name: String(m.customer_name ?? ''),
    // Total yang rusak jadi NaN, BUKAN 0: kasir yang melihat "Rp 0" akan
    // menerima pembayaran nol rupiah tanpa curiga apa pun.
    grand_total: total,
    subtotal: keAngka(m.subtotal),
    grossSubtotal: keAngka(m.gross_subtotal),
    diskon: keAngka(m.discount_total),
    promo: namaPromo(m.promotion),
    layanan: keAngka(m.service_charge),
    pajak: keAngka(m.tax),
    caraBayar: typeof m.payment_method === 'string' ? m.payment_method : null,
    niatBayar: typeof m.payment_preference === 'string' ? m.payment_preference : null,
    meja: typeof m.table_label === 'string' ? m.table_label : null,
    tipe: typeof m.order_type === 'string' ? m.order_type : null,
    waktuBayar: m.confirmed_at ? String(m.confirmed_at) : null,
    klaimBayar: m.customer_claimed_paid_at ? String(m.customer_claimed_paid_at) : null,
    siapPada: m.ready_at ? String(m.ready_at) : null,
    created_at: m.created_at ? String(m.created_at) : null,
    expires_at: m.expires_at ? String(m.expires_at) : null,
    items: Array.isArray(m.items)
      ? m.items.map((i: Record<string, unknown>) => ({
          nama: String(i.product_name ?? ''),
          qty: keAngka(i.qty),
          hargaSatuan: keAngka(i.unit_price),
          total: keAngka(i.line_total),
          // Catatan ikut ditampilkan: inilah satu-satunya cara dapur tahu
          // "tanpa gula" selama layar KDS belum ada.
          note: typeof i.note === 'string' ? i.note : '',
        }))
      : [],
  }
}

/** Antrean PENDING outlet ini. Filter status ditegakkan SERVER, bukan di layar. */
export async function ambilAntrean(): Promise<Pesanan[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/cashier/orders?status=pending')
  return Array.isArray(data) ? data.map(petakanPesanan) : []
}

// ===== POS kasir (buat pesanan walk-in / telepon / meja) =====

/** Satu produk di menu POS. */
export type ProdukMenu = {
  id: string
  nama: string
  harga: number
  habis: boolean
}

/** Satu kategori menu (berisi produk). */
export type KategoriMenu = {
  id: string
  nama: string
  produk: ProdukMenu[]
}

function petakanProdukMenu(m: Record<string, unknown>): ProdukMenu {
  return {
    id: String(m.id ?? ''),
    nama: typeof m.name === 'string' ? m.name : '',
    harga: keAngka(m.price),
    habis: m.is_out_of_stock === true,
  }
}

/** Menu POS kasir (kategori -> produk), scoped outlet + penanda habis. */
export async function ambilMenuKasir(): Promise<KategoriMenu[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/cashier/menu', undefined, ORDERING)
  return (Array.isArray(data) ? data : []).map((k) => ({
    id: String(k.id ?? ''),
    nama: typeof k.name === 'string' ? k.name : '',
    produk: Array.isArray(k.products) ? k.products.map(petakanProdukMenu) : [],
  }))
}

/** Meja aktif untuk POS dine-in. */
export type MejaKasir = { id: string; label: string }

export async function ambilMejaKasir(): Promise<MejaKasir[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/cashier/tables', undefined, ORDERING)
  return (Array.isArray(data) ? data : []).map((m) => ({
    id: String(m.id ?? ''),
    label: typeof m.label === 'string' ? m.label : '',
  }))
}

/** Input buat pesanan POS. */
export type InputPesananKasir = {
  orderType: 'dine_in' | 'takeaway'
  tableId: string | null
  customerName: string
  items: { product_id: string; qty: number }[]
}

/** Buat order POS. Harga & total DIHITUNG SERVER, tak pernah dari layar. */
export async function buatPesananKasir(input: InputPesananKasir): Promise<Pesanan> {
  const data = await panggil<Record<string, unknown>>(
    '/api/cashier/orders',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        order_type: input.orderType,
        table_id: input.tableId,
        customer_name: input.customerName,
        items: input.items,
      }),
    },
    ORDERING,
  )
  return petakanPesanan(data)
}

/**
 * Pesanan yang SUDAH dibayar di outlet ini.
 *
 * Filter status ditegakkan server, sama seperti antrean. Penyaringan "hari ini"
 * dan urutannya dikerjakan `riwayatHariIni()` di layar — lihat catatan ceiling
 * di antrean.ts soal kenapa itu sementara.
 */
export async function ambilRiwayat(): Promise<Pesanan[]> {
  // Tengah malam menurut jam PERANGKAT ini, dikirim sebagai instan UTC. Server
  // yang memangkasnya, jadi layar tak pernah mengunduh sejarah berbulan-bulan
  // hanya untuk membuang hampir semuanya.
  const sejak = new Date()
  sejak.setHours(0, 0, 0, 0)

  const data = await panggil<Record<string, unknown>[]>(
    `/api/cashier/orders?status=paid&paid_since=${encodeURIComponent(sejak.toISOString())}`,
  )

  return Array.isArray(data) ? data.map(petakanPesanan) : []
}

/** Data tren harian untuk dashboard owner. */
export type TrenHarian = {
  /** Tanggal WIB "YYYY-MM-DD" — kunci pengelompokan. */
  tanggal: string
  /** Label pendek sumbu-x, angka tanggal (mis. "15"). */
  label: string
  /** Label panjang untuk tooltip, mis. "Sab, 15 Agu". */
  labelPanjang: string
  total: number
  jumlah: number
}

/**
 * Zona waktu dashboard: Asia/Jakarta (WIB). DIPAKSA, bukan jam perangkat,
 * supaya angka yang sama terlihat dari perangkat mana pun (HP kasir, laptop
 * owner, atau browser tes di luar negeri). WIB tetap UTC+7 tanpa DST, jadi
 * selisihnya konstan dan aman dipakai untuk hitung-hitung hari.
 */
const ZONA_WIB = 'Asia/Jakarta'

/** "YYYY-MM-DD" dari sebuah instant, dihitung dalam WIB. */
function tanggalWIB(instant: Date): string {
  const bagian = new Intl.DateTimeFormat('en-US', {
    timeZone: ZONA_WIB,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(instant)
  const ambil = (t: string) => bagian.find(b => b.type === t)?.value ?? ''
  return `${ambil('year')}-${ambil('month')}-${ambil('day')}`
}

/** Kunci tanggal WIB dari ISO (server boleh kirim UTC maupun +07:00). null-safe. */
export function kunciTanggalWIB(iso: string | null): string | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return tanggalWIB(d)
}

/**
 * Instant WIB tengah malam, `mundur` hari ke belakang dari hari ini (0 = hari ini).
 *
 * `setTime` dipakai (bukan `setDate`) supaya pengurangan 24 jam tidak tergeser
 * oleh zona perangkat — `setDate` membaca jam LOKAL, yang bisa berbeda-beda.
 */
export function awalHariWIB(mundur: number): Date {
  const tgl = tanggalWIB(new Date())
  const d = new Date(`${tgl}T00:00:00+07:00`)
  d.setTime(d.getTime() - mundur * 86_400_000)
  return d
}

/** Semua pesanan lunas sejak `sejak` — buat dashboard owner. */
export async function ambilPesananDari(sejak: Date): Promise<Pesanan[]> {
  const data = await panggil<Record<string, unknown>[]>(
    `/api/cashier/orders?status=paid&paid_since=${encodeURIComponent(sejak.toISOString())}`,
  )
  return Array.isArray(data) ? data.map(petakanPesanan) : []
}

/**
 * Batas tanggal WIB (inklusif, "YYYY-MM-DD") periode saat ini dan sebelumnya.
 * `mulaiPrev`..`selesaiPrev` = N hari sebelum periode saat ini, buat % naik/turun.
 */
export function rentangPeriode(jumlahHari: number): {
  mulaiCur: string
  selesaiCur: string
  mulaiPrev: string
  selesaiPrev: string
} {
  return {
    mulaiCur: tanggalWIB(awalHariWIB(jumlahHari - 1)),
    selesaiCur: tanggalWIB(new Date()),
    mulaiPrev: tanggalWIB(awalHariWIB(2 * jumlahHari - 1)),
    selesaiPrev: tanggalWIB(awalHariWIB(jumlahHari)),
  }
}

/** Jumlahkan omset/transaksi/tunai/qris dalam rentang tanggal WIB [mulai, selesai]. */
export function ringkasPesanan(
  pesanan: Pesanan[],
  mulai: string,
  selesai: string,
): { total: number; jumlah: number; tunai: number; qris: number } {
  let total = 0
  let jumlah = 0
  let tunai = 0
  let qris = 0
  for (const p of pesanan) {
    const tgl = kunciTanggalWIB(p.waktuBayar)
    if (!tgl || tgl < mulai || tgl > selesai) continue
    total += p.grand_total ?? 0
    jumlah += 1
    if (p.caraBayar === 'cash') tunai += p.grand_total ?? 0
    else if (p.caraBayar === 'qris_static') qris += p.grand_total ?? 0
  }
  return { total, jumlah, tunai, qris }
}

/** Kelompokkan pesanan per hari (WIB) → tren grafik, N hari terakhir. */
export function trenDariPesanan(pesanan: Pesanan[], jumlahHari: number): TrenHarian[] {
  const peta: Record<string, { total: number; jml: number }> = {}
  for (const p of pesanan) {
    const tgl = kunciTanggalWIB(p.waktuBayar)
    if (!tgl) continue
    if (!peta[tgl]) peta[tgl] = { total: 0, jml: 0 }
    peta[tgl].total += p.grand_total ?? 0
    peta[tgl].jml += 1
  }

  const labelPanjang = new Intl.DateTimeFormat('id-ID', {
    timeZone: ZONA_WIB,
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  })

  const hasil: TrenHarian[] = []
  for (let i = jumlahHari - 1; i >= 0; i--) {
    const d = awalHariWIB(i)
    const tgl = tanggalWIB(d)
    hasil.push({
      tanggal: tgl,
      label: String(Number(tgl.slice(8, 10))),
      labelPanjang: labelPanjang.format(d),
      total: peta[tgl]?.total ?? 0,
      jumlah: peta[tgl]?.jml ?? 0,
    })
  }
  return hasil
}

/** Tanggal lengkap WIB, mis. "Jumat, 15 Agustus 2026". */
export function tanggalLengkapWIB(instant: Date = new Date()): string {
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: ZONA_WIB,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(instant)
}

/** Sapaan berdasar jam WIB: pagi/siang/sore/malam. */
export function sapaanWIB(instant: Date = new Date()): string {
  const jam = Number(
    new Intl.DateTimeFormat('en-US', { timeZone: ZONA_WIB, hour: 'numeric', hour12: false }).format(instant),
  )
  if (jam < 11) return 'Selamat pagi'
  if (jam < 15) return 'Selamat siang'
  if (jam < 19) return 'Selamat sore'
  return 'Selamat malam'
}

/** Tanggal + jam WIB ringkas, mis. "15 Agu 14.05". */
export function tanggalJamWIB(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: ZONA_WIB,
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)
}

/** Produk & total dari kumpulan pesanan. */
export function topDariPesanan(pesanan: Pesanan[]): { produk: string; jumlah: number; total: number }[] {
  const peta: Record<string, { jml: number; tot: number }> = {}
  for (const p of pesanan) {
    for (const item of p.items ?? []) {
      const nama = item.nama ?? 'Produk'
      if (!peta[nama]) peta[nama] = { jml: 0, tot: 0 }
      peta[nama].jml += item.qty ?? 0
      peta[nama].tot += (item.qty ?? 0) * (item.hargaSatuan ?? 0)
    }
  }
  return Object.entries(peta)
    .map(([produk, { jml, tot }]) => ({ produk, jumlah: jml, total: tot }))
    .sort((a, b) => b.total - a.total)
}

/**
 * Satu pesanan, dibaca ulang dari server.
 *
 * Nota SELALU dirakit dari sini, tak pernah dari salinan yang dititipkan layar
 * antrean. Dua alasan: hanya jawaban server yang memuat waktu konfirmasi dan
 * cara bayar yang benar-benar tercatat, dan cetak ulang — yang bisa terjadi
 * berjam-jam kemudian dari layar riwayat — tak punya salinan apa pun untuk
 * dibawa.
 */
export async function ambilPesanan(id: string): Promise<Pesanan> {
  const data = await panggil<Record<string, unknown>>(
    `/api/cashier/orders/${encodeURIComponent(id)}`,
  )

  return petakanPesanan(data)
}

/**
 * Tandai pesanan sudah dibayar. Ini SATU-SATUNYA titik di seluruh sistem yang
 * mengubah uang jadi nyata (order.paid -> stok terpotong, laporan tercatat),
 * jadi cara bayarnya wajib disebut eksplisit oleh kasir — tak ada default.
 */
export async function konfirmasiBayar(id: string, cara: CaraBayar): Promise<Pesanan> {
  // Pesanan hasil konfirmasi dikembalikan, bukan dibuang: nota dicetak dari
  // jawaban SERVER, bukan dari salinan yang dipegang layar. Bedanya nyata —
  // hanya jawaban server yang memuat waktu konfirmasi dan cara bayar yang
  // benar-benar tercatat.
  const data = await panggil<Record<string, unknown>>(
    `/api/cashier/orders/${encodeURIComponent(id)}/confirm-payment`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_method: cara }),
    },
  )

  return petakanPesanan(data)
}

/**
 * Tandai pesanan selesai dibuat.
 *
 * Server menolak apa pun yang belum PAID: barang tak boleh dinyatakan keluar
 * sebelum uangnya diterima. Penandaan kedua dibiarkan lolos TANPA menggeser
 * jamnya — kasir yang menekan dua kali tak boleh membuat pesanan yang sudah
 * menunggu sepuluh menit terlihat baru saja selesai.
 *
 * Yang menekannya hari ini kasir, besok layar dapur. Endpoint-nya sengaja tak
 * menyebut siapa.
 */
export async function tandaiSiap(id: string): Promise<Pesanan> {
  const data = await panggil<Record<string, unknown>>(
    `/api/cashier/orders/${encodeURIComponent(id)}/ready`,
    { method: 'POST' },
  )

  return petakanPesanan(data)
}

/**
 * Batalkan pesanan yang belum dibayar.
 *
 * Alasannya sengaja tetap, bukan diketik kasir: satu kolom teks bebas di
 * tengah antrean sibuk cuma akan diisi asal, dan kolom alasan yang isinya
 * sampah lebih menyesatkan daripada tak ada. Kalau nanti alasan benar-benar
 * dipakai untuk menganalisis pembatalan, ganti dengan daftar pilihan.
 */
export async function batalkanPesanan(id: string): Promise<void> {
  await panggil(`/api/cashier/orders/${encodeURIComponent(id)}/cancel`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ reason: 'Dibatalkan kasir dari layar antrean' }),
  })
}

export type Meja = {
  id: string
  label: string
  aktif: boolean
  /**
   * Kunci yang membuka meja ini bagi pelanggan.
   *
   * Di endpoint lain kolom ini `$hidden` di model — ia cuma dibuka untuk owner,
   * dan cuma karena owner perlu mencetaknya. Perlakukan seperti kredensial:
   * jangan pernah dikirim ke mana pun selain QR di layar ini.
   */
  qrToken: string
}

export function petakanMeja(m: Record<string, unknown>): Meja {
  return {
    id: String(m.id),
    label: String(m.label ?? ''),
    // Kolomnya tinyint: Eloquent mengirim true/false hari ini, tapi cast yang
    // berubah membuatnya datang sebagai 1/0. Keduanya diterima; apa pun selain
    // itu dianggap TIDAK aktif — meja yang keliru dianggap aktif berarti QR
    // yang seharusnya mati tetap menerima pesanan.
    aktif: m.is_active === true || m.is_active === 1,
    qrToken: typeof m.qr_token === 'string' ? m.qr_token : '',
  }
}

/**
 * Alamat yang ditanam di dalam QR meja.
 *
 * Menunjuk app PELANGGAN, bukan app ini — dan karena itu wajib URL penuh dari
 * VITE_CUSTOMER_URL, bukan path relatif seperti alamat service lainnya. Yang
 * memindainya HP orang lain: path relatif akan dibaca sebagai alamat app staf,
 * dan QR-nya mati di tangan pelanggan pertama.
 *
 * null kalau belum disetel — layar menolak menggambar QR daripada membuat
 * owner mencetak setumpuk stiker yang menunjuk "undefined".
 */
export function urlMeja(qrToken: string): string | null {
  const basis: unknown = import.meta.env.VITE_CUSTOMER_URL

  if (typeof basis !== 'string' || basis === '') return null

  return `${basis.replace(/\/+$/, '')}/t/${qrToken}`
}

/** Semua meja outlet ini, sudah diurut label oleh server. */
export async function ambilMeja(): Promise<Meja[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/tables')

  return Array.isArray(data) ? data.map(petakanMeja) : []
}

export async function buatMeja(label: string): Promise<Meja> {
  const data = await panggil<Record<string, unknown>>('/api/tables', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ label }),
  })

  return petakanMeja(data)
}

/**
 * Ubah label atau status aktif.
 *
 * Hanya yang disebut yang dikirim: server memakai `sometimes`, jadi mengirim
 * label saat yang diubah cuma saklarnya berarti ikut menimpa nama meja dengan
 * salinan yang mungkin sudah basi di layar.
 */
export async function ubahMeja(
  id: string,
  ubah: { label?: string; aktif?: boolean },
): Promise<Meja> {
  const badan: Record<string, unknown> = {}
  if (ubah.label !== undefined) badan.label = ubah.label
  if (ubah.aktif !== undefined) badan.is_active = ubah.aktif

  const data = await panggil<Record<string, unknown>>(`/api/tables/${encodeURIComponent(id)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(badan),
  })

  return petakanMeja(data)
}

/**
 * Terbitkan QR baru untuk meja ini. QR lama langsung mati.
 *
 * Identitas mejanya tidak berubah, jadi riwayat pesanan lama tetap menempel.
 * Dipakai saat stiker QR difoto orang dan mulai dipakai dari luar kafe.
 */
export async function putarQr(id: string): Promise<Meja> {
  const data = await panggil<Record<string, unknown>>(
    `/api/tables/${encodeURIComponent(id)}/rotate-qr`,
    { method: 'POST' },
  )

  return petakanMeja(data)
}

/**
 * Rentang yang boleh diisi owner, DATANG DARI SERVER.
 *
 * Sengaja tidak ditulis sebagai konstanta di sini. Angka batas yang disalin ke
 * layar akan tetap terlihat benar setelah aturannya berubah di server — form
 * memandu ke maksimum lama, server menolak di maksimum baru, dan tak ada satu
 * pun test yang merah. Lihat SettingController::limits().
 */
export type BatasSetelan = {
  tax_percent_max: number
  service_charge_percent_max: number
  order_expiry_minutes_min: number
  order_expiry_minutes_max: number
  qris_max_kilobytes: number
  qris_max_pixels: number
  outlet_name_max: number
  outlet_address_max: number
  outlet_phone_max: number
}

export type Setelan = {
  pajakPersen: number
  layananPersen: number
  kedaluwarsaMenit: number
  /** Alamat gambar QRIS, relatif terhadap Ordering. null = belum dipasang. */
  qrisUrl: string | null
  /**
   * Identitas yang tercetak di kepala struk. null = belum diisi, dan barisnya
   * tak dicetak sama sekali — beda dari string kosong yang tetap memakan
   * kertas.
   */
  namaOutlet: string | null
  alamatOutlet: string | null
  teleponOutlet: string | null
  batas: BatasSetelan
}

/**
 * Bentuk mentah Ordering -> bentuk kita.
 *
 * Persen datang sebagai string ("11.00") karena kolomnya decimal — keAngka()
 * yang menjaga itu, dan nilai tak terbaca jadi NaN alih-alih 0. Nol di layar
 * ini bukan sekadar salah tampilan: owner yang melihat "Pajak 0%" padahal
 * servernya menagih 11% tak punya alasan untuk curiga.
 */
/**
 * Teks dari server -> teks atau null.
 *
 * String kosong ikut jadi null: kolom yang pernah diisi lalu dikosongkan bisa
 * pulang sebagai "", dan membedakan "" dari null di seluruh layar berarti dua
 * keadaan yang artinya sama harus diurus dua kali.
 */
function teksAtauNull(nilai: unknown): string | null {
  return typeof nilai === 'string' && nilai.trim() !== '' ? nilai : null
}

export function petakanSetelan(m: Record<string, unknown>): Setelan {
  const batas = (m.limits ?? {}) as Record<string, unknown>

  return {
    pajakPersen: keAngka(m.tax_percent),
    layananPersen: keAngka(m.service_charge_percent),
    kedaluwarsaMenit: keAngka(m.order_expiry_minutes),
    qrisUrl: typeof m.qris_image_url === 'string' ? m.qris_image_url : null,
    namaOutlet: teksAtauNull(m.outlet_name),
    alamatOutlet: teksAtauNull(m.outlet_address),
    teleponOutlet: teksAtauNull(m.outlet_phone),
    batas: {
      tax_percent_max: keAngka(batas.tax_percent_max),
      service_charge_percent_max: keAngka(batas.service_charge_percent_max),
      order_expiry_minutes_min: keAngka(batas.order_expiry_minutes_min),
      order_expiry_minutes_max: keAngka(batas.order_expiry_minutes_max),
      qris_max_kilobytes: keAngka(batas.qris_max_kilobytes),
      qris_max_pixels: keAngka(batas.qris_max_pixels),
      outlet_name_max: keAngka(batas.outlet_name_max),
      outlet_address_max: keAngka(batas.outlet_address_max),
      outlet_phone_max: keAngka(batas.outlet_phone_max),
    },
  }
}

/**
 * Alamat gambar QRIS yang bisa dipasang di <img>.
 *
 * `qris_image_url` relatif terhadap ORDERING, bukan terhadap app ini. Dipasang
 * apa adanya, browser akan mencarinya di host app staf dan owner melihat kotak
 * rusak persis di layar yang seharusnya meyakinkannya bahwa QRIS-nya benar
 * terpasang. URL absolut (kolom yang sama boleh memuatnya) dibiarkan utuh.
 */
export function urlQris(jalur: string): string {
  return /^https?:\/\//.test(jalur) ? jalur : `${ORDERING}${jalur}`
}

/**
 * Alamat foto produk yang bisa dipasang di <img>.
 *
 * `image_url` relatif terhadap CATALOG, bukan terhadap app ini — sepola
 * urlQris() di atas. URL absolut (kolom yang sama boleh memuatnya) dibiarkan
 * utuh.
 */
export function urlGambar(jalur: string | null): string | null {
  if (jalur === null) return null
  return /^https?:\/\//.test(jalur) ? jalur : `${CATALOG}${jalur}`
}

export async function ambilSetelan(): Promise<Setelan> {
  return petakanSetelan(await panggil<Record<string, unknown>>('/api/settings'))
}

/**
 * Simpan tarif outlet.
 *
 * Ketiganya dikirim sekaligus walau owner cuma mengubah satu: server memakai
 * fill(), jadi yang tak dikirim tetap seperti semula — tapi mengirim seluruh
 * form berarti yang tersimpan persis yang dilihat owner di layar saat menekan
 * Simpan, bukan gabungan antara ketikannya dan nilai yang mungkin sudah diubah
 * orang lain di sela-selanya.
 */
export async function simpanSetelan(nilai: {
  pajakPersen: number
  layananPersen: number
  kedaluwarsaMenit: number
  namaOutlet: string
  alamatOutlet: string
  teleponOutlet: string
}): Promise<Setelan> {
  // Kotak yang dikosongkan owner dikirim sebagai null, BUKAN "". Server
  // menerima dua-duanya, tapi string kosong tersimpan sebagai baris kosong yang
  // tetap memakan kertas di struk — sedangkan null berarti barisnya tak
  // dicetak sama sekali. Yang dimaksud owner saat menghapus isinya jelas yang
  // kedua.
  const bersih = (teks: string): string | null => (teks.trim() === '' ? null : teks.trim())

  const data = await panggil<Record<string, unknown>>('/api/settings', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      tax_percent: nilai.pajakPersen,
      service_charge_percent: nilai.layananPersen,
      order_expiry_minutes: nilai.kedaluwarsaMenit,
      outlet_name: bersih(nilai.namaOutlet),
      outlet_address: bersih(nilai.alamatOutlet),
      outlet_phone: bersih(nilai.teleponOutlet),
    }),
  })

  return petakanSetelan(data)
}

/**
 * Unggah gambar QRIS outlet.
 *
 * Content-Type SENGAJA tidak diset: batas multipart dibangkitkan browser
 * berikut nilai acaknya, dan menuliskannya sendiri membuat server tak bisa
 * memisahkan bagian berkas dari bagian lain.
 */
export async function unggahQris(berkas: File): Promise<Setelan> {
  const form = new FormData()
  form.append('qris', berkas)

  const data = await panggil<Record<string, unknown>>('/api/settings/qris', {
    method: 'POST',
    body: form,
  })

  return petakanSetelan(data)
}

/**
 * Peran pemilik token ini, dibaca dari klaim JWT.
 *
 * HANYA untuk memutuskan apa yang perlu ditampilkan. Klaim ini datang dari
 * localStorage dan bisa dikarang siapa saja yang membuka DevTools — yang
 * menegakkan izin tetap `role:owner` di server, dan endpoint setelan membalas
 * 403 untuk kasir berapa kali pun tautannya dipaksa muncul. Menyembunyikan
 * tautan itu soal tidak menawarkan pintu yang pasti terkunci, bukan soal
 * mengunci pintunya.
 */
/**
 * Satu peringatan di inbox kasir. Sumbernya event stok dari Inventory.
 *
 * `tingkat` dibiarkan string bebas, bukan union tertutup: nilainya lahir di
 * service lain, dan nilai baru di sana tak boleh membuat seluruh inbox gagal
 * dipetakan. Yang belum dikenal jatuh ke tampilan netral.
 */
export type Notifikasi = {
  id: string
  jenis: string
  tingkat: string
  judul: string
  isi: string
  waktu: string | null
  sudahDibaca: boolean
}

export function petakanNotifikasi(m: Record<string, unknown>): Notifikasi {
  return {
    id: String(m.id ?? ''),
    jenis: typeof m.type === 'string' ? m.type : '',
    tingkat: typeof m.severity === 'string' ? m.severity : 'info',
    // Judul kosong lebih baik daripada "undefined" di layar kasir.
    judul: typeof m.title === 'string' ? m.title : '',
    isi: typeof m.body === 'string' ? m.body : '',
    waktu: typeof m.created_at === 'string' ? m.created_at : null,
    // Sengaja `=== true`, bukan truthy: apa pun selain "sudah dibaca" yang
    // tegas harus jatuh ke BELUM dibaca. Peringatan stok yang diam-diam
    // dianggap terbaca adalah peringatan yang tak pernah sampai.
    sudahDibaca: m.read === true,
  }
}

export async function ambilNotifikasi(): Promise<Notifikasi[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/notifications', undefined, NOTIFICATION)

  return (Array.isArray(data) ? data : []).map(petakanNotifikasi)
}

/**
 * Angka di lonceng.
 *
 * Jawabannya `{unread: N}` tanpa bungkus `data`, jadi lewat mintaJson().
 * Nilai tak terbaca jadi 0 — lonceng yang menampilkan NaN lebih membingungkan
 * daripada lonceng yang diam, dan daftarnya sendiri tetap bisa dibuka.
 */
export async function hitungBelumDibaca(): Promise<number> {
  const json = await mintaJson('/api/notifications/unread-count', undefined, NOTIFICATION)
  const jumlah = Number(json.unread)

  return Number.isFinite(jumlah) && jumlah >= 0 ? jumlah : 0
}

export async function tandaiSemuaDibaca(): Promise<void> {
  await panggil('/api/notifications/read-all', { method: 'POST' }, NOTIFICATION)
}

/**
 * Saldo satu bahan di outlet ini.
 *
 * `nama` boleh null dan itu BUKAN kelalaian: namanya tinggal di Catalog, dan
 * Inventory sengaja tetap membalas 200 saat Catalog tak terjangkau — angka
 * saldonya sendiri tetap benar. Layar wajib menyiapkan penggantinya.
 */
export type Stok = {
  id: string
  nama: string | null
  qty: number
  minimum: number | null
  diperbarui: string | null
}

export function petakanStok(m: Record<string, unknown>): Stok {
  const minimum = keAngka(m.min_stock)

  return {
    id: String(m.ingredient_id ?? ''),
    // Nama kosong ('') diperlakukan sama dengan tak ada: keduanya sama-sama tak
    // memberi tahu kasir bahan apa ini, dan satu jalur pengganti lebih sedikit
    // daripada dua.
    nama: typeof m.ingredient_name === 'string' && m.ingredient_name !== '' ? m.ingredient_name : null,
    // NaN, bukan 0. Saldo yang tak terbaca lalu ditampilkan sebagai nol
    // berbunyi "habis" — dan "habis" adalah kalimat yang membuat orang berhenti
    // menjual sesuatu yang sebenarnya masih ada.
    qty: keAngka(m.qty_on_hand),
    minimum: Number.isNaN(minimum) ? null : minimum,
    diperbarui: typeof m.updated_at === 'string' ? m.updated_at : null,
  }
}

export async function ambilStok(): Promise<Stok[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/stock', undefined, INVENTORY)

  return (Array.isArray(data) ? data : []).map(petakanStok)
}

/**
 * Barang masuk. `qty` DITAMBAHKAN ke saldo, bukan menggantikannya.
 *
 * Tak mengembalikan apa pun: balasan server adalah model mentah
 * `stock_balances` (bukan daftar-izin seperti /api/stock), jadi memetakannya di
 * sini berarti menyalin bentuk yang sengaja tak dijanjikan ke layar. Pemanggil
 * memuat ulang daftarnya — satu permintaan tambahan, nol tebakan.
 */
export async function restokBahan(ingredientId: string, qty: number): Promise<void> {
  await mintaJson(
    '/api/stock/restock',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ingredient_id: ingredientId, qty }),
    },
    INVENTORY,
  )
}

/**
 * Opname: hasil hitung fisik. Angka ini MENGGANTIKAN saldo, dan selisihnya
 * dicatat server sebagai gerakan tersendiri — jadi kesalahan ketik di sini
 * bukan cuma mengubah angka, ia menulis satu baris riwayat yang salah.
 */
export async function opnameBahan(ingredientId: string, hasilHitung: number): Promise<void> {
  await mintaJson(
    '/api/stock/adjust',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ingredient_id: ingredientId, counted_qty: hasilHitung }),
    },
    INVENTORY,
  )
}

/**
 * Laporan satu shift. X-report selama shift berjalan (berubah tiap penjualan),
 * Z-report begitu ditutup (beku).
 *
 * `kasDihitung` dan `selisih` null selama shift belum ditutup — belum ada yang
 * menghitung isi laci, dan menampilkan 0 di situ berarti memberi tahu kasir
 * bahwa lacinya kosong.
 */
export type LaporanShift = {
  totalPenjualan: number
  transaksi: number
  tunai: number
  qris: number
  modalAwal: number
  /** modalAwal + tunai. QRIS tak menyentuh laci, jadi tak ikut. */
  kasSeharusnya: number
  kasDihitung: number | null
  /** kasDihitung - kasSeharusnya. Boleh minus: itu justru angka yang dicari. */
  selisih: number | null
}

export type Shift = {
  id: string
  /** `open` | `closed` apa adanya dari server. */
  status: string
  modalAwal: number
  dibukaPada: string | null
  ditutupPada: string | null
  laporan: LaporanShift | null
}

function petakanLaporan(m: Record<string, unknown>): LaporanShift {
  const dihitung = keAngka(m.counted_cash)
  const selisih = keAngka(m.cash_variance)

  return {
    totalPenjualan: keAngka(m.total_sales),
    transaksi: keAngka(m.transactions),
    tunai: keAngka(m.cash_sales),
    qris: keAngka(m.qris_sales),
    modalAwal: keAngka(m.opening_cash),
    kasSeharusnya: keAngka(m.expected_cash),
    // null server (shift masih buka) dan angka rusak sama-sama jadi null di
    // sini: dua-duanya berarti "belum ada jawabannya", dan rupiah() sudah
    // menolak menampilkan NaN sebagai angka.
    kasDihitung: Number.isNaN(dihitung) ? null : dihitung,
    selisih: Number.isNaN(selisih) ? null : selisih,
  }
}

export function petakanShift(m: Record<string, unknown>): Shift {
  const laporan = m.report

  return {
    id: String(m.id ?? ''),
    status: typeof m.status === 'string' ? m.status : '',
    modalAwal: keAngka(m.opening_cash),
    dibukaPada: typeof m.opened_at === 'string' ? m.opened_at : null,
    ditutupPada: typeof m.closed_at === 'string' ? m.closed_at : null,
    laporan:
      laporan !== null && typeof laporan === 'object'
        ? petakanLaporan(laporan as Record<string, unknown>)
        : null,
  }
}

/**
 * Shift yang sedang terbuka di outlet ini, atau null.
 *
 * Ditanyakan ke SERVER, tak pernah disimpan di perangkat: shift dibuka kasir
 * pagi dan ditutup kasir sore, sering di tablet yang berbeda. Id yang tinggal
 * di localStorage satu perangkat berarti shift yang tak bisa ditutup dari
 * perangkat lain — dan karena satu outlet cuma boleh punya satu shift terbuka,
 * kas outletnya macet sampai ada yang membuka database.
 */
export async function ambilShiftBerjalan(): Promise<Shift | null> {
  const data = await panggil<Record<string, unknown> | null>('/api/shifts/current', undefined, FINANCE)

  return data === null || typeof data !== 'object' ? null : petakanShift(data)
}

export async function bukaShift(modalAwal: number): Promise<Shift> {
  const data = await panggil<Record<string, unknown>>(
    '/api/shifts/open',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ opening_cash: modalAwal }),
    },
    FINANCE,
  )

  return petakanShift(data)
}

export async function tutupShift(id: string, kasDihitung: number): Promise<Shift> {
  const data = await panggil<Record<string, unknown>>(
    `/api/shifts/${encodeURIComponent(id)}/close`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ closing_cash: kasDihitung }),
    },
    FINANCE,
  )

  return petakanShift(data)
}

/**
 * Riwayat shift yang sudah DITUTUP outlet ini, terbaru di atas (maks. 20).
 *
 * Inilah yang membuat shift tertutup tetap terlihat setelah layar di-refresh —
 * sebelumnya hanya ada `current` (shift berjalan) dan tak ada pintu baca untuk
 * shift yang sudah lewat, sehingga begitu ditutup ia "hilang" dari layar.
 */
export async function ambilRiwayatShift(): Promise<Shift[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/shifts', undefined, FINANCE)
  return Array.isArray(data) ? data.map(petakanShift) : []
}

/**
 * Laporan laba-rugi lintas-hari (Finance, owner saja). Omzet − harga pokok
 * (HPP) = laba kotor; laba kotor − pengeluaran = laba bersih.
 */
export type Laporan = {
  period: { from: string; to: string }
  sales: { total: number; cash: number; qris: number; transactions: number }
  cogs: number
  gross_profit: number
  margin_percent: number
  expenses: { total: number; count: number; by_category: Record<string, number> }
  net: number
}

export type Pengeluaran = {
  id: string
  category: string
  amount: number
  note: string | null
  spent_at: string | null
}

function petakanPengeluaran(m: Record<string, unknown>): Pengeluaran {
  return {
    id: String(m.id ?? ''),
    category: typeof m.category === 'string' ? m.category : '',
    amount: keAngka(m.amount),
    note: typeof m.note === 'string' && m.note !== '' ? m.note : null,
    spent_at: typeof m.spent_at === 'string' ? m.spent_at : null,
  }
}

/** Laporan laba-rugi rentang tanggal WIB. from/to = "YYYY-MM-DD". */
export async function ambilLaporan(from: string, to: string): Promise<Laporan> {
  const data = await panggil<Record<string, unknown>>(
    `/api/reports?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
    undefined,
    FINANCE,
  )

  const period = (data.period ?? {}) as Record<string, unknown>
  const sales = (data.sales ?? {}) as Record<string, unknown>
  const expenses = (data.expenses ?? {}) as Record<string, unknown>
  const byCategory = (expenses.by_category ?? {}) as Record<string, unknown>

  return {
    period: {
      from: typeof period.from === 'string' ? period.from : from,
      to: typeof period.to === 'string' ? period.to : to,
    },
    sales: {
      total: keAngka(sales.total),
      cash: keAngka(sales.cash),
      qris: keAngka(sales.qris),
      transactions: keAngka(sales.transactions),
    },
    cogs: keAngka(data.cogs),
    gross_profit: keAngka(data.gross_profit),
    margin_percent: keAngka(data.margin_percent),
    expenses: {
      total: keAngka(expenses.total),
      count: keAngka(expenses.count),
      by_category: Object.fromEntries(
        Object.entries(byCategory).map(([k, v]) => [k, keAngka(v)]),
      ),
    },
    net: keAngka(data.net),
  }
}

export async function ambilPengeluaran(): Promise<Pengeluaran[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/expenses', undefined, FINANCE)

  return (Array.isArray(data) ? data : []).map(petakanPengeluaran)
}

/** Catat pengeluaran. Kategori tertutup: bahan | operasional | gaji | lain. */
export async function catatPengeluaran(kategori: string, nominal: number, catatan: string | null): Promise<void> {
  await mintaJson(
    '/api/expenses',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ category: kategori, amount: nominal, note: catatan }),
    },
    FINANCE,
  )
}

export type Kategori = {
  id: string
  nama: string
  aktif: boolean
}

export type Produk = {
  id: string
  nama: string
  harga: number
  kategoriId: string | null
  tersedia: boolean
  /** Alamat foto mentah — relatif terhadap CATALOG. Pakai urlGambar() saat render. */
  gambarUrl: string | null
}

export function petakanKategori(m: Record<string, unknown>): Kategori {
  return {
    id: String(m.id ?? ''),
    nama: typeof m.name === 'string' ? m.name : '',
    // Kategori nonaktif menyembunyikan SELURUH produk di dalamnya dari menu
    // pelanggan, jadi keadaannya harus terbaca di layar owner — bukan cuma
    // dipakai untuk mengurutkan.
    aktif: m.is_active !== false && m.is_active !== 0,
  }
}

export function petakanProduk(m: Record<string, unknown>): Produk {
  return {
    id: String(m.id ?? ''),
    nama: typeof m.name === 'string' ? m.name : '',
    // NaN, bukan 0: harga yang tak terbaca lalu tampil sebagai Rp 0 adalah
    // harga yang benar-benar akan ditagihkan kalau owner tak sadar.
    harga: keAngka(m.price),
    kategoriId: typeof m.category_id === 'string' && m.category_id !== '' ? m.category_id : null,
    tersedia: m.is_available !== false && m.is_available !== 0,
    gambarUrl: typeof m.image_url === 'string' && m.image_url !== '' ? m.image_url : null,
  }
}

export async function ambilKategori(): Promise<Kategori[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/categories', undefined, CATALOG)

  return (Array.isArray(data) ? data : []).map(petakanKategori)
}

export async function buatKategori(nama: string): Promise<void> {
  await mintaJson(
    '/api/categories',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: nama }),
    },
    CATALOG,
  )
}

export async function ambilProduk(): Promise<Produk[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/products', undefined, CATALOG)

  return (Array.isArray(data) ? data : []).map(petakanProduk)
}

/**
 * `kategoriId` WAJIB di sini walau server masih menerima null.
 *
 * Produk tanpa kategori tak pernah muncul di menu pelanggan — MenuController
 * hanya menelusuri produk lewat kategori aktif. Kerusakannya sunyi total: owner
 * membuat produk, produknya tak pernah tampil, tak ada pesan apa pun. Tipe yang
 * menolak null di sini adalah pagar pertama; pagar sungguhannya nanti di
 * StoreProductRequest (lihat catatan tindak lanjut).
 */
export async function buatProduk(nama: string, harga: number, kategoriId: string): Promise<void> {
  await mintaJson(
    '/api/products',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: nama, price: harga, category_id: kategoriId }),
    },
    CATALOG,
  )
}

/** Hanya yang disebut yang dikirim — server memakai `sometimes`. */
export async function ubahProduk(
  id: string,
  ubah: { harga?: number; tersedia?: boolean; kategoriId?: string },
): Promise<void> {
  const badan: Record<string, unknown> = {}
  if (ubah.harga !== undefined) badan.price = ubah.harga
  if (ubah.tersedia !== undefined) badan.is_available = ubah.tersedia
  if (ubah.kategoriId !== undefined) badan.category_id = ubah.kategoriId

  await mintaJson(
    `/api/products/${encodeURIComponent(id)}`,
    {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(badan),
    },
    CATALOG,
  )
}

/**
 * Unggah foto produk (multipart). Content-Type SENGAJA tidak diset — batas
 * multipart dibangkitkan browser berikut nilai acaknya, sepola unggahQris().
 * Setelah ini produk punya `image_url`, dan layar memuat ulang daftarnya.
 */
export async function unggahFotoProduk(id: string, berkas: File): Promise<void> {
  const form = new FormData()
  form.append('image', berkas)

  await panggil<Record<string, unknown>>(
    `/api/products/${encodeURIComponent(id)}/image`,
    { method: 'POST', body: form },
    CATALOG,
  )
}

/**
 * Satuan dasar bahan — cermin `enum('g','ml','pcs')` di migrasi `ingredients`.
 *
 * ponytail: daftar disalin, bukan diambil dari server, karena Catalog tak punya
 * endpoint yang menyebutkannya. Kalau kelak satuan bertambah (l, kg), yang salah
 * bukan layar ini melainkan dua tempat yang harus diubah bersamaan — pindahkan
 * ke `GET /api/units` saat itu terjadi, jangan sekarang.
 */
export const SATUAN = ['g', 'ml', 'pcs'] as const

export type Bahan = {
  id: string
  nama: string
  satuan: string
  /** Harga beli per satuan dasar (g/ml/pcs), integer rupiah. 0 = belum diisi. */
  hargaBeli: number
}

export type BarisResep = {
  id: string
  produkId: string
  bahanId: string
  takaran: number
}

export function petakanBahan(m: Record<string, unknown>): Bahan {
  const hargaBeli = keAngka(m.cost_per_unit)
  return {
    id: String(m.id ?? ''),
    nama: typeof m.name === 'string' ? m.name : '',
    satuan: typeof m.unit === 'string' ? m.unit : '',
    // Bahan lama (sebelum ada kolom harga beli) tak punya cost_per_unit -> 0.
    hargaBeli: Number.isFinite(hargaBeli) ? hargaBeli : 0,
  }
}

export function petakanResep(m: Record<string, unknown>): BarisResep {
  return {
    id: String(m.id ?? ''),
    produkId: String(m.product_id ?? ''),
    bahanId: String(m.ingredient_id ?? ''),
    // NaN, bukan 0, sepola harga: takaran yang tak terbaca lalu tampil sebagai 0
    // adalah resep yang tak pernah memotong stok — persis kerusakan sunyi yang
    // layar resep ini ada untuk mencegahnya.
    takaran: keAngka(m.qty_per_unit),
  }
}

export async function ambilBahan(): Promise<Bahan[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/ingredients', undefined, CATALOG)

  return (Array.isArray(data) ? data : []).map(petakanBahan)
}

export async function buatBahan(nama: string, satuan: string, hargaBeli = 0): Promise<void> {
  await mintaJson(
    '/api/ingredients',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: nama, unit: satuan, cost_per_unit: hargaBeli }),
    },
    CATALOG,
  )
}

/** Ubah harga beli bahan — dasar hitung HPP. Hanya harga beli yang dikirim. */
export async function ubahHargaBeli(id: string, hargaBeli: number): Promise<void> {
  await mintaJson(
    `/api/ingredients/${encodeURIComponent(id)}`,
    {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cost_per_unit: hargaBeli }),
    },
    CATALOG,
  )
}

export async function hapusBahan(id: string): Promise<void> {
  await mintaJson(`/api/ingredients/${encodeURIComponent(id)}`, { method: 'DELETE' }, CATALOG)
}

/**
 * Karyawan satu tenant. Owner ikut di daftar — dia juga staff.
 *
 * IAM membalas ARRAY TELANJANG di `/api/staff`, tanpa bungkus `data`, jadi
 * lewat mintaJson() langsung dan bukan panggil().
 */
export type Staf = {
  id: string
  nama: string
  email: string
  peran: string
  aktif: boolean
}

export function petakanStaf(m: Record<string, unknown>): Staf {
  return {
    id: String(m.id ?? ''),
    nama: typeof m.name === 'string' ? m.name : '',
    email: typeof m.email === 'string' ? m.email : '',
    peran: typeof m.role === 'string' ? m.role : '',
    // === true, bukan Boolean(): medan yang hilang atau bertipe aneh harus
    // terbaca sebagai NONAKTIF. Menampilkan akun mati sebagai aktif membuat
    // owner mengira mantan karyawannya masih bisa masuk — dan tak memeriksanya.
    aktif: m.is_active === true,
  }
}

export async function ambilStaf(): Promise<Staf[]> {
  const data: unknown = await mintaJson('/api/staff', undefined, IAM)

  return Array.isArray(data) ? data.map(petakanStaf) : []
}

/**
 * Buat akun kasir. Peran, tenant, dan outlet TIDAK dikirim — server mengisinya
 * dari token owner, jadi layar ini secara struktural tak bisa membuat owner
 * kedua betapa pun payload-nya dikarang.
 */
export async function buatKasir(nama: string, email: string, sandi: string): Promise<void> {
  await mintaJson(
    '/api/staff',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: nama, email, password: sandi }),
    },
    IAM,
  )
}

/**
 * Ganti sandi sendiri. Sandi lama WAJIB — server menuntutnya supaya token yang
 * bocor tak bisa jadi pengambilalihan akun permanen.
 *
 * Sandi lama yang salah dibalas 422, dan mintaJson() sudah menerjemahkan itu
 * jadi kalimat server apa adanya. Sengaja BUKAN 401: itu akan memulangkan
 * orangnya ke layar login padahal sesinya baik-baik saja.
 */
export async function gantiSandi(lama: string, baru: string): Promise<void> {
  await mintaJson(
    '/api/auth/password',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        current_password: lama,
        password: baru,
        password_confirmation: baru,
      }),
    },
    IAM,
  )
}

/** Reset sandi kasir yang lupa — owner, tanpa menyebut sandi lama. */
export async function resetSandiStaf(id: string, baru: string): Promise<void> {
  await mintaJson(
    `/api/staff/${encodeURIComponent(id)}`,
    {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: baru }),
    },
    IAM,
  )
}

export async function ubahAktifStaf(id: string, aktif: boolean): Promise<void> {
  await mintaJson(
    `/api/staff/${encodeURIComponent(id)}`,
    {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ is_active: aktif }),
    },
    IAM,
  )
}

/** Seluruh resep tenant sekaligus — pemanggilnya menyaring per produk. */
export async function ambilResep(): Promise<BarisResep[]> {
  const data = await panggil<Record<string, unknown>[]>('/api/recipes', undefined, CATALOG)

  return (Array.isArray(data) ? data : []).map(petakanResep)
}

export async function buatResep(produkId: string, bahanId: string, takaran: number): Promise<void> {
  await mintaJson(
    '/api/recipes',
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        product_id: produkId,
        ingredient_id: bahanId,
        qty_per_unit: takaran,
      }),
    },
    CATALOG,
  )
}

/** Server hanya menerima takaran — ganti produk/bahan berarti resep baru. */
export async function ubahTakaran(id: string, takaran: number): Promise<void> {
  await mintaJson(
    `/api/recipes/${encodeURIComponent(id)}`,
    {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ qty_per_unit: takaran }),
    },
    CATALOG,
  )
}

export async function hapusResep(id: string): Promise<void> {
  await mintaJson(`/api/recipes/${encodeURIComponent(id)}`, { method: 'DELETE' }, CATALOG)
}

export function peranSaya(): string | null {
  const bagian = bacaToken()?.split('.')[1]
  if (!bagian) return null

  try {
    // base64url -> base64, lalu padding dikembalikan: atob() menolak panjang
    // yang bukan kelipatan empat, dan JWT memang membuang '=' di ujungnya.
    const b64 = bagian.replace(/-/g, '+').replace(/_/g, '/')
    const sisa = b64.length % 4
    const payload: unknown = JSON.parse(atob(sisa === 0 ? b64 : b64 + '='.repeat(4 - sisa)))
    const peran = (payload as Record<string, unknown>).role

    return typeof peran === 'string' ? peran : null
  } catch {
    // Token cacat bukan alasan menjatuhkan layar: pemanggilnya cuma akan
    // menyembunyikan tautan, dan permintaan pertama ke server yang akan
    // memulangkan owner ke login kalau tokennya memang tak sah.
    return null
  }
}
