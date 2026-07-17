# Blueprint — Service Ordering (F2)

Status: **desain terkunci** (disetujui Vincent 2026-07-17). Belum ada kode.
Prasyarat: IAM (`b6ecd5d`) & Catalog (`46cee2f`) jalan.

## Tujuan

"Apa yang dipesan & sudah dibayar belum" — pemilik **satu-satunya** atas status order.
Tidak ada service lain yang boleh mengubah status order. Ordering yang menerbitkan `OrderPaid`.

## Alur (meja + pay-first — revisi final 2026-07-17)

```
Customer scan QR meja → GET /api/t/{qr_token} (tahu tenant+outlet+meja)
  → frontend ambil menu dari Catalog → customer isi cart (di browser)
  → POST /api/orders  ................ status PENDING, masuk antrean kasir berlabel meja
  → customer datang ke kasir, sebut "meja X"
  → kasir buka tab meja X, sebut total → customer bayar (QRIS statis / cash)
  → POST /api/cashier/orders/{id}/confirm-payment  ... status PAID + baris outbox (1 transaksi)
  → [nanti] relay → RabbitMQ `order.paid` → Inventory / Finance / Printing / Realtime
```

Customer **tidak pernah** melapor sudah bayar. Tidak ada `PAYMENT_REPORTED`, tidak ada open-bill.

## Keputusan yang dikunci

| # | Keputusan | Alasan |
|---|---|---|
| 1 | Harga **di-snapshot server-side** dari Catalog (`GET /api/menu?tenant=<uuid>`) saat order dibuat. Harga kiriman client **selalu diabaikan**. | Client tidak boleh menentukan uang. Harga naik besok ≠ mengubah struk kemarin. Catalog down = order baru gagal (diterima; cache fallback bisa nyusul tanpa ubah skema). |
| 2 | Cart hidup di client. Order lahir langsung `PENDING`. Tidak ada status `DRAFT`. | Menghindari baris sampah & status ambigu. |
| 3 | Satu meja boleh punya **beberapa order `PENDING`**. Tidak ada entitas tab/sesi gabungan. | Satu grup = satu order = satu bayar. Tab gabungan = open-bill terselubung (ditolak). |
| 4 | QR meja memuat `qr_token` acak (bukan UUID meja) → URL `/t/<qr_token>`, bisa dirotasi. | QR bukan rahasia, tapi rotasi harus mungkin tanpa ganti ID meja. |
| 5 | Tarif PPN & service charge **milik Ordering** (tabel `order_settings` per outlet), bukan IAM. | Tarif = aturan transaksi, bukan identitas. Yang menghitung total harus memiliki angkanya, dan tidak boleh gantung ke IAM saat hitung. |
| 6 | Tabel `outbox` ditulis **sekarang**, di dalam transaksi PAID. Relay worker ke RabbitMQ **nanti** (F3/F4, saat consumer ada). | Barisnya yang mengunci invarian keuangan; relay cuma pengangkut. |

## Uang: integer rupiah — TITIK

Kontrak `shared/contracts/events/order-paid.event.json` mewajibkan `subtotal`/`service_charge`/`tax`/`grand_total` bertipe **integer rupiah**. Catalog menyimpan `products.price` sebagai `decimal(12,2)`.

Aturan konversi (jangan diimprovisasi di controller):
- Saat snapshot: `unit_price = (int) round((float) $catalogPrice)` — dibulatkan sekali, di batas sistem, lalu **tidak pernah** jadi float lagi.
- Semua kolom uang di Ordering bertipe `unsignedBigInteger` (rupiah bulat).
- Urutan hitung (ditetapkan, karena hasilnya beda kalau dibalik):
  1. `line_total = unit_price * qty`
  2. `subtotal = Σ line_total`
  3. `service_charge = (int) round(subtotal * service_charge_percent / 100)`
  4. `tax = (int) round((subtotal + service_charge) * tax_percent / 100)` — PPN dikenakan **setelah** service charge (praktik umum F&B Indonesia)
  5. `grand_total = subtotal + service_charge + tax`
- `tax_percent` & `service_charge_percent` **di-snapshot ke baris order**. Owner ubah tarif besok ≠ mengubah order kemarin.

## Model data

DB terpisah: `fnbsense_ordering` (+ `fnbsense_ordering_test`).
`tenant_id`/`outlet_id`/`product_id` = referensi **logis** ke service lain → UUID + index, **TANPA FK** (beda DB). FK hanya untuk relasi internal Ordering.

| Tabel | Kolom inti |
|---|---|
| `tables` | id (UUID), tenant_id (UUID), outlet_id (UUID), label ("Meja 1"), qr_token (string 32, **unique**), is_active, timestamps · unique(`tenant_id`,`outlet_id`,`label`) |
| `order_settings` | id (UUID), tenant_id (UUID), outlet_id (UUID **unique**), tax_percent (decimal 5,2), service_charge_percent (decimal 5,2), order_expiry_minutes (int, default 30), timestamps |
| `orders` | id (UUID), tenant_id, outlet_id, table_id (UUID nullable → FK internal, null utk takeaway), order_number (string, unik per outlet per hari), order_type (enum dine_in/takeaway), customer_name, status (enum pending/paid/cancelled/expired, default pending), subtotal, service_charge, tax, grand_total (semua unsignedBigInteger), tax_percent, service_charge_percent (snapshot), payment_method (enum qris_static/cash, nullable), confirmed_by (UUID nullable), confirmed_at (nullable), expires_at, note (nullable), timestamps · index(`tenant_id`,`outlet_id`,`status`), index(`status`,`expires_at`) |
| `order_items` | id (UUID), order_id (FK internal → orders, cascade), product_id (UUID, tanpa FK), product_name (**snapshot**), unit_price, qty, line_total, note (nullable), timestamps |
| `outbox` | id (UUID), aggregate_type ("order"), aggregate_id (UUID), event_type ("order.paid"), payload (json), occurred_at, published_at (nullable), timestamps · index(`published_at`) |

`order_items.product_name` di-snapshot supaya struk lama tetap terbaca walau produk dihapus/di-rename di Catalog.

## State machine

```
            ┌── confirm-payment (kasir) ──→ PAID ── terminal, idempotent
PENDING ────┼── cancel (kasir) ───────────→ CANCELLED
            └── expire (scheduler) ───────→ EXPIRED
```

- **PAID itu terminal & absolut.** Tidak ada satu pun endpoint yang boleh memindahkan order keluar dari PAID. Uang sudah masuk.
- Transisi hanya sah dari `PENDING`. Semua transisi memakai `UPDATE ... WHERE id=? AND status='pending'` di dalam transaksi + `lockForUpdate()` — bukan cek-lalu-tulis (race dua kasir).
- `confirm-payment` **idempotent**: kalau order sudah PAID, kembalikan `200` berisi state sekarang **tanpa** menulis baris outbox kedua. Kalau `CANCELLED`/`EXPIRED` → `409`.
- Penulisan status PAID + baris `outbox` terjadi dalam **satu transaksi lokal**. Kalau salah satu gagal, dua-duanya batal.

## Endpoint

**Publik — tanpa login, wajib rate limit:**
- `GET /api/t/{qr_token}` → `{tenant_id, outlet_id, table_id, label}`. Meja non-aktif/token tak dikenal → `404`.
- `POST /api/orders` → body: `qr_token`, `order_type`, `customer_name`, `items[{product_id, qty, note}]`. Tenant/outlet **diambil dari qr_token**, bukan dari body.
- `GET /api/orders/{id}` → status + rincian untuk customer polling. Field terbatas (tanpa `confirmed_by` dsb).

**Kasir — `middleware(['jwt','role:cashier,owner'])`:**
- `GET /api/cashier/orders?status=pending&table_id=` → antrean, di-scope `tenant_id`+`outlet_id` dari token.
- `GET /api/cashier/orders/{id}`
- `POST /api/cashier/orders/{id}/confirm-payment` → body `payment_method` → PAID + outbox.
- `POST /api/cashier/orders/{id}/cancel` → body `reason`.

**Owner — `middleware(['jwt','role:owner'])`:**
- `GET/POST /api/tables`, `PUT/DELETE /api/tables/{id}`
- `POST /api/tables/{id}/rotate-qr` → terbitkan `qr_token` baru (QR lama mati).
- `GET/PUT /api/settings` → tarif pajak, service charge, expiry.

## Skrutini keamanan (wajib, ini service uang)

1. **Harga & total tidak pernah dari client.** Body `POST /api/orders` cuma boleh `product_id` + `qty`; harga apa pun yang dikirim client diabaikan diam-diam (jangan divalidasi "harus sama" — cukup abaikan).
2. **Isolasi tenant.** Endpoint kasir/owner: setiap query di-scope `tenant_id` **dan** `outlet_id` dari klaim JWT. Order milik tenant lain → `404` (bukan `403` — jangan bocorkan keberadaannya). Endpoint customer: tenant ditentukan `qr_token`, jadi customer secara struktural tak bisa lintas tenant.
3. **Validasi produk milik tenant yang sama.** Setiap `product_id` harus ada di respons menu Catalog untuk tenant itu **dan** `is_available` — kalau tidak, tolak `422`. Ini yang mencegah pesan produk tenant lain.
4. **Rate limit endpoint publik** (`throttle`): `POST /api/orders` per IP + per `qr_token`. Tanpa ini, satu orang bisa membanjiri antrean kasir. Order fiktif sudah ditekan alur "harus hadir fisik", tapi spam antrean tetap mungkin.
5. **Mass assignment**: `status`, `confirmed_by`, `confirmed_at`, dan semua kolom uang **tidak boleh** `fillable`. Diisi eksplisit di service layer.
6. **Batas qty**: `integer|min:1|max:99` per item + batas jumlah item per order (mis. 50). Anti order absurd & overflow.
7. **`qr_token`** dibangkitkan `Str::random(32)` (CSPRNG), unique, dan **bukan** turunan dari ID meja.
8. **Idempotensi PAID** = pertahanan utama double-charge & double-potong-stok. Wajib punya test khusus: dua request confirm bersamaan → satu PAID, **satu** baris outbox.
9. Jangan log body order + `customer_name` di level info (PII ringan).

## Urutan implementasi (satu langkah = satu review — mode Vincent)

1. Scaffold Laravel `services/ordering` (**cek `.git` nested sebelum commit** — lihat [[lessons-scaffold-nested-git]]).
2. Salin pola auth stateless dari Catalog: `AuthenticateJwt`, `EnsureRole`, `ForceJsonResponse`, handler `JWTException`→401, `.env` RS256 **public key saja** (lihat [[lessons-stateless-service-auth]]).
3. Migration + model: `tables`, `order_settings`.
4. CRUD meja + rotate-qr + settings (owner).
5. `CatalogClient` (HTTP ke Catalog, timeout + error jelas kalau Catalog down).
6. Migration + model: `orders`, `order_items`, `outbox`.
7. `POST /api/orders` + kalkulator total (kelas terpisah, unit-testable).
8. Antrean kasir + `confirm-payment` (transaksi + lock + outbox) + `cancel`.
9. Command `orders:expire` (scheduler) → PENDING kedaluwarsa jadi EXPIRED.
10. Feature test (pola `services/iam/tests/Feature/AuthTest.php`), termasuk test idempotensi & isolasi tenant.

## Catatan

- Relay outbox → RabbitMQ: **F3/F4**, saat sudah ada consumer. Sampai itu, baris outbox menumpuk dengan `published_at = null` — itu normal, bukan bug.
- Verifikasi bayar dibungkus abstraksi (`PaymentVerifier`): manual kasir sekarang, webhook QRIS dinamis nanti tanpa ubah state machine.
- Variant/addon belum ada di Catalog → `order_items` belum punya `variant_id`/addons. Kontrak event sudah menyediakan field opsionalnya; tambahkan saat Catalog tahap lanjut selesai.
