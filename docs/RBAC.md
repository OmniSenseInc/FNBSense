# RBAC — Role & Batasan Akses

Dokumen **acuan** (bukan kode). Enum role hidup di IAM (`app/Enums/UserRole.php`), tapi *"boleh ngapain"* ditegakkan **per aksi** lewat middleware `role:` di tiap service — bertahap, muncul saat fitur dibangun. Tabel ini kontekannya biar konsisten.

## Role

| Role | Merangkap | Ringkas |
|---|---|---|
| `owner` | + manager | Pemilik/pengelola. Akses penuh: menu, harga, stok, laporan, staf, konfigurasi. |
| `cashier` | + dapur/kitchen | Operasional harian: terima & verifikasi pembayaran, lihat pesanan masuk (dapur). |

> Prinsip: mulai seminimal mungkin (2 role). Tambah role baru = tambah 1 case di enum + baris di tabel ini. Jangan bikin role yang belum ada kebutuhan nyatanya (YAGNI).

## Matriks kemampuan

| Kemampuan | Owner | Cashier |
|---|:---:|:---:|
| **Auth** — login, lihat profil sendiri | ✅ | ✅ |
| **Staf** — tambah/nonaktifkan user, atur role | ✅ | ❌ |
| **Outlet** — kelola outlet & pengaturan | ✅ | ❌ |
| **Menu/Katalog** — CRUD produk, variant, addon, resep/BOM | ✅ | ❌ |
| **Harga** — ubah harga, promo, bundle | ✅ | ❌ |
| **Order** — lihat pesanan masuk (dapur/KDS) | ✅ | ✅ |
| **Pembayaran** — verifikasi lapor-bayar → set `PAID` | ✅ | ✅ |
| **Pembayaran** — batalkan/void order | ✅ | ⚠️ dengan alasan |
| **Stok** — lihat saldo stok | ✅ | ✅ |
| **Stok** — koreksi/opname, atur min-stok | ✅ | ❌ |
| **Keuangan** — lihat penjualan, COGS, margin, expense | ✅ | ❌ |
| **Shift** — buka/tutup shift kasir | ✅ | ✅ |
| **Laporan** — dashboard analytics, leaderboard, trend | ✅ | ❌ |
| **Notifikasi** — atur low-stock alert, WhatsApp | ✅ | ❌ |

Keterangan: ✅ boleh · ❌ tidak · ⚠️ boleh dengan syarat (dicatat/beri alasan).

## Cara enforcement (bertahap, saat fitur dibuat)

1. IAM menaruh `role` (juga `tenant_id`, `outlet_id`) sebagai **custom claim** di JWT.
2. Tiap service verifikasi JWT & baca claim `role` tanpa memanggil IAM.
3. Endpoint yang perlu proteksi dipasang middleware, contoh:
   ```php
   Route::post('orders/{id}/confirm-paid', ...)->middleware('role:owner,cashier');
   Route::apiResource('products', ...)->middleware('role:owner');
   ```
4. **Isolasi tenant wajib**: setiap query difilter `tenant_id` dari JWT — role owner pun cuma boleh menyentuh data tenant-nya sendiri.

## Catatan

- Aturan di tabel ini **belum semuanya diimplementasi** — baru jadi kontrak saat service/fitur terkait dibangun (lihat roadmap fase di [ARCHITECTURE.md](ARCHITECTURE.md)).
- Perubahan role/kemampuan → update tabel ini dulu, baru sesuaikan middleware.
