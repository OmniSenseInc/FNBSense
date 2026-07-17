# Security TODO — Catalog

Catatan keamanan dari desain auth lintas-service (stateless RS256). Bukan lubang kritis, tapi konsekuensi arsitektur yang perlu ditindak saat fitur terkait dibangun.

## MEDIUM
- [ ] **Logout/blacklist tak terlihat oleh Catalog** — Catalog verifikasi JWT stateless (public key), tanpa storage bersama. Kalau IAM logout/blacklist sebuah token, Catalog tetap menganggapnya sah sampai `exp` (TTL 60m). Mitigasi saat perlu: TTL pendek + Redis blacklist bersama antar-service, atau introspeksi token ke IAM untuk aksi paling sensitif.
- [ ] **User/tenant dinonaktifkan** — token yang sudah terbit tetap sah di Catalog sampai TTL walau tenant/user di-suspend di IAM. Sama akar masalahnya dengan poin di atas (butuh shared revocation).

## LOW
- [ ] **Menu publik tanpa rate limit** — `GET /api/menu` publik & tanpa auth. Tambah `throttle` sebelum go-live untuk cegah scraping/abuse. Pertimbangkan cache per-tenant (menu jarang berubah).
- [ ] **Enumerasi tenant via `?tenant=<uuid>`** — endpoint menu balas data untuk UUID tenant valid. Risiko rendah (UUID tak bisa ditebak), tapi kalau nanti pindah ke slug/subdomain, pastikan tak membocorkan keberadaan tenant.
- [ ] **Produk tanpa kategori tak tampil di menu** — `MenuController` hanya menampilkan produk lewat kategori aktif. Produk `category_id = null` tak muncul. Putuskan saat UI menu: butuh grup "Lainnya" atau paksa kategori wajib.

## Sudah AMAN (diterapkan by design)
`tenant_id` dipaksa dari token (bukan body), semua query di-scope `tenant_id` (anti IDOR → 404), `category_id` divalidasi milik tenant yang sama (anti tempel lintas-tenant), tulis dibatasi `role:owner` (403), JWTException → 401 bersih, RS256 verifikasi public-key-only (Catalog tak pegang private key), ForceJson (anti 500 redirect login).
