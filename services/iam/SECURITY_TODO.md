# Security TODO — IAM

Temuan dari security review (2026-07-16) yang **sengaja ditunda** — bukan lubang kritis (0 CRITICAL / 0 HIGH), tapi perlu ditindak saat fitur terkait dibangun.

## MEDIUM
- [ ] **Revoke token saat user/tenant dinonaktifkan** — `is_active` cuma dicek saat login; token yang sudah terbit tetap sah sampai TTL (60m). Saat bikin fitur suspend/deaktivasi (mis. respons fraud), tambahkan middleware re-check `is_active` di DB untuk endpoint sensitif, atau blacklist semua token user.
- [ ] **User enumeration di `register`** — rule `unique:users,email` membocorkan email mana yang sudah terdaftar. Trade-off UX vs privasi. Keputusan: diterima untuk sekarang; kalau mau dikeraskan, rate-limit per-email.

## LOW
- [x] **Password policy** — DONE (2026-07-25): `Password::defaults()` = min 8 + `mixedCase()` + `numbers()` di `AppServiceProvider::boot()`. `uncompromised()` sengaja TIDAK dipakai (butuh API HIBP eksternal → registrasi lambat & test flaky). Ditest: `test_register_menolak_password_lemah`.
- [ ] **Refresh window** — `/auth/refresh` di balik `auth:api`, jadi token expired tak bisa di-refresh (refresh_ttl 2 minggu praktis tak terpakai). Kalau mau refresh window jalan, tangani token expired-tapi-dalam-window secara manual.
- [ ] **Mass assignment lintas-tenant (future)** — saat bikin `OutletController`/`TenantController`: JANGAN ambil `tenant_id` dari request body, selalu inject dari `auth()->user()->tenant_id`. Jangan izinkan user ubah `is_active` tenant-nya sendiri (anti self-reactivation).
- [ ] **Hard delete tenant → cascade user** — pertimbangkan `SoftDeletes` atau `restrictOnDelete()` untuk jaga audit trail.

## Sudah AMAN (diverifikasi review)
Privilege escalation (role/tenant_id dipaksa server), mass assignment controller, password hashing, RS256 + key di-gitignore, blacklist/logout, rate-limit auth, login tak bocorkan status akun, IDOR endpoint yang ada, info-leak JWTException (401 bersih), debug production dipaksa mati.
