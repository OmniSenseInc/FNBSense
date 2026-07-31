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
export const hapusToken = () => localStorage.removeItem(KUNCI_TOKEN)

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
  const peran = (json.user as Record<string, unknown> | undefined)?.role

  if (typeof token !== 'string' || token === '') {
    throw new Error('Login gagal. Coba lagi sebentar.')
  }
  if (typeof peran !== 'string' || !PERAN_BOLEH.includes(peran)) {
    throw new Error('Akun ini tidak punya akses kasir. Minta owner mengubah perannya.')
  }

  simpanToken(token)
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
 * Permintaan ber-token ke Ordering, dengan satu kali penukaran token saat 401.
 *
 * Dibungkus di satu tempat justru supaya tak ada pemanggil yang lupa: setiap
 * endpoint kasir bisa kena 401 kapan saja, dan yang lupa menanganinya akan
 * terlihat sebagai "layar tiba-tiba kosong" di tengah jam sibuk.
 */
async function panggil<T>(jalur: string, init?: RequestInit): Promise<T> {
  const token = bacaToken()
  if (!token) throw new Error(SESI_HABIS)

  const kirim = (t: string) =>
    fetch(`${ORDERING}${jalur}`, {
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
  if (res.status === 409) throw new Error('Pesanan sudah tidak bisa diproses. Antrean diperbarui.')
  if (res.status === 404) throw new Error('Pesanan tidak ditemukan di outlet ini.')
  if (!res.ok) throw new Error('Gagal menghubungi server. Coba lagi.')

  const json = await jsonDari(res)
  return json.data as T
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
  layanan: number
  pajak: number
  /** Terisi setelah kasir mengonfirmasi; inilah yang masuk laporan. */
  caraBayar: string | null
  /** Niat pelanggan saat memesan. Petunjuk, bukan keputusan. */
  niatBayar: string | null
  waktuBayar: string | null
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
    layanan: keAngka(m.service_charge),
    pajak: keAngka(m.tax),
    caraBayar: typeof m.payment_method === 'string' ? m.payment_method : null,
    niatBayar: typeof m.payment_preference === 'string' ? m.payment_preference : null,
    waktuBayar: m.confirmed_at ? String(m.confirmed_at) : null,
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
