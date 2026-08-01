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
}

export type Setelan = {
  pajakPersen: number
  layananPersen: number
  kedaluwarsaMenit: number
  /** Alamat gambar QRIS, relatif terhadap Ordering. null = belum dipasang. */
  qrisUrl: string | null
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
export function petakanSetelan(m: Record<string, unknown>): Setelan {
  const batas = (m.limits ?? {}) as Record<string, unknown>

  return {
    pajakPersen: keAngka(m.tax_percent),
    layananPersen: keAngka(m.service_charge_percent),
    kedaluwarsaMenit: keAngka(m.order_expiry_minutes),
    qrisUrl: typeof m.qris_image_url === 'string' ? m.qris_image_url : null,
    batas: {
      tax_percent_max: keAngka(batas.tax_percent_max),
      service_charge_percent_max: keAngka(batas.service_charge_percent_max),
      order_expiry_minutes_min: keAngka(batas.order_expiry_minutes_min),
      order_expiry_minutes_max: keAngka(batas.order_expiry_minutes_max),
      qris_max_kilobytes: keAngka(batas.qris_max_kilobytes),
      qris_max_pixels: keAngka(batas.qris_max_pixels),
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
}): Promise<Setelan> {
  const data = await panggil<Record<string, unknown>>('/api/settings', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      tax_percent: nilai.pajakPersen,
      service_charge_percent: nilai.layananPersen,
      order_expiry_minutes: nilai.kedaluwarsaMenit,
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
