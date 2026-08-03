# Security TODO — IAM

Temuan dari security review (2026-07-16) yang **sengaja ditunda** — bukan lubang kritis (0 CRITICAL / 0 HIGH), tapi perlu ditindak saat fitur terkait dibangun.

## MEDIUM
- [~] **Revoke token saat user/tenant dinonaktifkan** — SEBAGIAN DONE (2026-07-26, F-iam-b). Middleware `role` (`App\Http\Middleware\EnsureRole`) sekarang membaca ulang `is_active` dari DB, jadi akun yang disuspend kehilangan hak istimewanya SEKETIKA di semua route ber-`role:`. Gratis: guard `api` memang sudah menghidupkan model User. Ditest: `test_akun_nonaktif_ditolak_walau_tokennya_masih_berlaku`. SISA: route ber-`auth:api` polos (`/auth/me`, `/auth/logout`) masih menerima token lama sampai TTL 15m habis — dinilai tak berbahaya bagi akun tersuspend. Denylist bersama (Redis) tetap ditunda sampai ada kebutuhan cabut lintas-service.
- [ ] **User enumeration di `register`** — rule `unique:users,email` membocorkan email mana yang sudah terdaftar. Trade-off UX vs privasi. Keputusan: diterima untuk sekarang; kalau mau dikeraskan, rate-limit per-email.

- [x] **Klaim basi setelah refresh — LUNAS 2026-08-03.** Akar masalahnya BUKAN seperti dugaan di bawah. `persistent_claims` di `config/jwt.php` ternyata kosong, jadi paket tak menyalin apa pun dari token lama lewat jalur itu. Yang basi adalah **instance User-nya**: `getJWTCustomClaims()` membaca dari objek yang sudah dipegang guard (diresolusi dari token lama), sehingga peran lama ikut terbawa. Itu juga sebab percobaan `logout()`+`login()` dulu gagal — polanya benar, user yang disuapkan ke situ yang basi.
  **Perbaikan:** `refresh()` tetap dipakai untuk menegakkan `refresh_ttl` dan mem-blacklist token lama, tapi tokennya tak dikembalikan ke pemanggil. `sub` diambil dari situ, user dimuat ULANG lewat `User::find($sub)`, konteks dicek, token perantara di-blacklist, lalu token akhir diterbitkan dari user. **Dibukti mutasi:** `User::find($sub)` diganti `$guard->user()` → `test_refresh_menyegarkan_klaim_dari_database` merah dengan `-'cashier' +'owner'`. Suite IAM 33 hijau / 138 assertion. `markTestSkipped`-nya sudah dibuka.

  <details><summary>Diagnosis lama (keliru — disimpan sebagai jejak)</summary>

- [ ] **Klaim basi setelah refresh (TERVERIFIKASI 2026-07-26)** — `POST /auth/refresh` menyalin `tenant_id`/`outlet_id`/`role` dari token LAMA, bukan membacanya ulang dari DB. Karena keenam service hilir hanya membaca klaim dan tak pernah menanyakan DB, staff yang baru diturunkan haknya tetap membawa peran lamanya selama ia rajin refresh — sampai `refresh_ttl` habis, yaitu **14 hari**. Bandingkan dengan jendela 15 menit yang dijaga ketat di tempat lain.
  **Bukti reproduksi:** `AuthTest::test_refresh_menyegarkan_klaim_dari_database` (saat ini `markTestSkipped`). Jalankan tanpa skip -> merah dengan `-'cashier' +'owner'`.
  **Percobaan perbaikan yang GAGAL:** menerbitkan ulang lewat `guard()->logout()` lalu `guard()->login($user)` di dalam `refresh()` TIDAK menghasilkan klaim segar — token tetap membawa peran lama. Dugaan: state internal paket (PayloadFactory/Manager `customClaims`) bertahan dalam satu request. Jangan ulangi pendekatan itu tanpa memastikan mekanismenya lebih dulu.
  **Arah yang belum dicoba:** tulis ulang `refresh()` tanpa memakai `guard()->refresh()` sama sekali — urai token lama (toleran kedaluwarsa), ambil `sub`, muat user, verifikasi konteks, blacklist token lama, lalu terbitkan token baru dari user lewat jalur yang bersih.

  </details>

## LOW
- [x] **Password policy** — DONE (2026-07-25): `Password::defaults()` = min 8 + `mixedCase()` + `numbers()` di `AppServiceProvider::boot()`. `uncompromised()` sengaja TIDAK dipakai (butuh API HIBP eksternal → registrasi lambat & test flaky). Ditest: `test_register_menolak_password_lemah`.
- [x] **Refresh window** — DONE (2026-07-25, Opsi A): `/auth/refresh` dikeluarkan dari `auth:api` + `refresh()` menukar token dulu (toleran expired dalam refresh_ttl) baru cek konteks. TTL access token diperpendek 60→15m (window token dicabut mengecil). Ditest: `test_refresh_token_expired_dalam_window_tetap_berhasil` + `_di_luar_window_ditolak`.
- [ ] **Mass assignment lintas-tenant (future)** — saat bikin `OutletController`/`TenantController`: JANGAN ambil `tenant_id` dari request body, selalu inject dari `auth()->user()->tenant_id`. Jangan izinkan user ubah `is_active` tenant-nya sendiri (anti self-reactivation).
- [ ] **Hard delete tenant → cascade user** — pertimbangkan `SoftDeletes` atau `restrictOnDelete()` untuk jaga audit trail.

## Sudah AMAN (diverifikasi review)
Privilege escalation (role/tenant_id dipaksa server), mass assignment controller, password hashing, RS256 + key di-gitignore, blacklist/logout, rate-limit auth, login tak bocorkan status akun, IDOR endpoint yang ada, info-leak JWTException (401 bersih), debug production dipaksa mati.
