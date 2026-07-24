# Dashboard Owner — F6b

Status: **MVP selesai dan terverifikasi** pada 2026-07-23.

## Tujuan

Memberi owner tampilan ringkas yang langsung menjawab:

- berapa omzet dan transaksi pada suatu periode;
- berapa nilai transaksi rata-rata;
- bagaimana komposisi cash dan QRIS;
- bagaimana tren omzet harian; dan
- produk mana yang paling banyak terjual.

## Alur data dan keamanan

```text
Browser owner
  -> Dashboard /admin (session cookie)
      -> IAM /api/auth/login (email + password)
      <- JWT + profil tenant/outlet
      -> Reporting API (Bearer JWT)
      <- summary, daily trend, top products
```

- IAM tetap menjadi sumber kebenaran user dan role.
- Hanya role `owner` dengan `tenant_id` dan `outlet_id` lengkap yang diterima.
- Password hanya diteruskan saat login dan tidak disimpan oleh Dashboard.
- JWT dan profil berada dalam session server. Browser hanya menerima session cookie.
- Session hanya dapat dipulihkan selama JWT IAM belum kedaluwarsa.
- Reporting melakukan pembatasan data dari claim `tenant_id` dan `outlet_id` JWT.
- Dashboard tidak membaca MySQL Reporting atau database service lain secara langsung.
- Logout mencoba mencabut JWT di IAM dan selalu membersihkan session Dashboard.

### Matriks akses MVP

| Akses | Owner | Kasir |
|---|---:|---:|
| Login ke Dashboard owner | boleh | ditolak |
| Summary/trend/top product Reporting | boleh, sesuai tenant/outlet JWT | ditolak |
| Memilih `tenant_id`/`outlet_id` lewat request Dashboard | tidak tersedia | tidak tersedia |

Menyembunyikan menu di UI bukan mekanisme keamanan. Otorisasi tetap dilakukan dua
lapis: Dashboard menolak selain owner, lalu Reporting kembali memverifikasi tanda
tangan JWT dan role `owner`.

### Aturan integrasi service berikutnya

Jika Dashboard kelak membaca Catalog, Inventory, atau Finance:

1. Dashboard hanya memanggil API service tersebut; tidak boleh membaca database-nya.
2. JWT IAM diteruskan sebagai Bearer token dari server Dashboard.
3. `tenant_id`, `outlet_id`, dan role harus diambil service tujuan dari claim JWT
   terverifikasi, bukan dari query/body yang dikirim browser.
4. Setiap query repository wajib memakai scope tenant dan, untuk data outlet,
   scope outlet sekaligus.
5. Setiap endpoint tetap memiliki allowlist role sendiri. Kasir tidak otomatis
   mendapat endpoint owner hanya karena sudah login ke aplikasi kasir.
6. Tes kontrak wajib membuktikan role yang salah mendapat `403` serta token tenant A
   tidak pernah melihat record tenant B, termasuk ketika ID outlet sengaja dibuat sama.

## Komponen UI

- Filter tanggal `from` dan `to`, default 30 hari terakhir.
- Kartu omzet, jumlah transaksi, average order value, serta cash/QRIS.
- Line chart omzet harian.
- Tabel 10 produk terlaris berdasarkan kuantitas dan omzet item.
- Empty state saat periode belum memiliki penjualan.
- Pesan aman saat Reporting tidak dapat dihubungi; token tidak ditulis ke log.

## Konfigurasi

Dashboard berjalan di port pengembangan `8006`.

```dotenv
APP_URL=http://localhost:8006
IAM_URL=http://127.0.0.1:8000
REPORTING_URL=http://127.0.0.1:8005
SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
CACHE_STORE=file
```

Dashboard tidak memerlukan koneksi MySQL sendiri. MySQL tetap digunakan oleh service
domain yang memilikinya, termasuk Reporting.

## Batas MVP

- Belum ada metrik margin/COGS karena event `OrderPaid` belum membawa kontrak biaya.
- Belum ada export PDF/Excel atau perbandingan antar-outlet.
- Belum ada gateway/deployment production untuk Dashboard; saat ini service dijalankan
  lokal pada port `8006`.
- JWT downstream diverifikasi stateless. Token yang sudah dicuri dapat tetap valid
  sampai `exp` meskipun logout telah memasukkannya ke blacklist IAM. Karena itu
  production harus memakai HTTPS, TTL access token pendek, rotasi key, dan membatasi
  Reporting sebagai API internal/gateway-protected.

Untuk production HTTPS, set `SESSION_SECURE_COOKIE=true`; nilai ini sengaja `false`
pada konfigurasi lokal HTTP agar login pengembangan tetap berfungsi.

## Bukti pengujian isolasi

- IAM: 16 test/58 assertion lulus, termasuk tenant/outlet nonaktif dan outlet yang
  bukan milik tenant user.
- Reporting: 9 test/63 assertion lulus, termasuk role kasir ditolak, claim malformed
  ditolak, isolasi outlet, dan tenant A versus tenant B pada semua endpoint analytics.
- Dashboard: 8 test/35 assertion lulus, termasuk login owner, penolakan kasir,
  session role yang dimanipulasi, token kedaluwarsa, dan Bearer token tanpa parameter
  tenant/outlet dari browser.
- `composer audit` IAM, Reporting, dan Dashboard bersih. Guzzle IAM dinaikkan ke
  `7.15.1` untuk menutup tiga advisory medium pada versi lock sebelumnya.
