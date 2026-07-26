# Runbook E2E — F4d Inventory (bukti `PAID → stok`, idempoten lintas-restart)

Membuktikan rantai penuh lewat **broker sungguhan**, bukan test yang mem-fake channel:

```
POST /orders → confirm-payment → outbox → outbox:relay → RabbitMQ
             → inventory:consume → potong stok (ledger + saldo) → saga
```

Definisi selesai (INVENTORY.md, F4d): **potong sekali per order**, dan **idempoten
lintas-restart** — matikan consumer, bayar lagi, nyalakan → backlog terproses tanpa dobel;
event `order.paid` yang sama di-redeliver → di-ACK & dibuang (dedup `processed_orders`).

> **Shell:** perintah di bawah ditulis untuk PowerShell (pemisah `;`). Windows
> PowerShell 5.1 TIDAK mengenal `&&` — memakainya menghasilkan parser error.

Test ini dijalankan manual oleh operator. Tidak ada skrip yang menyalakan MySQL + RabbitMQ +
3 service sekaligus — sengaja: tiap langkah dilihat hasilnya biar sistematikanya kebaca.

---

## 0. Prasyarat (sekali set)

**Infra**
- MySQL jalan; DB `fnbsense_catalog`, `fnbsense_ordering`, `fnbsense_inventory` ada.
- RabbitMQ jalan; user `fnbsense` punya akses vhost `/`. Cek UI di http://localhost:15672.

**Konfigurasi tiap service** (`cp .env.example .env` lalu isi):
- `services/catalog`, `services/ordering`, `services/inventory`: `php artisan key:generate`, `migrate`.
- Copy `jwt-public.pem` IAM ke `storage/keys/` di **ordering** dan **inventory** (lihat README service).
- **`CATALOG_SERVICE_TOKEN` di `services/inventory/.env` HARUS sama persis** dengan yang di-set di
  service Catalog (`services/catalog/.env`). Ini shared-secret `X-Service-Token`; beda sedikit → Catalog tolak.
- `RABBITMQ_*` **identik** di ordering & inventory (exchange `fnbsense.events` dipakai bersama).

**Nyalakan (4 terminal + broker/DB):**
```
# T0  IAM (dibutuhkan sejak 2026-07-26: kasir dibuat lewat API, bukan di-mint tangan)
cd services/iam; php artisan serve --port=8002
# T1  Catalog
cd services/catalog; php artisan serve --port=8001
# T2  Ordering
cd services/ordering; php artisan serve --port=8000
# T3  Inventory API (untuk seed stok owner-only; opsional kalau seed via tinker)
cd services/inventory; php artisan serve --port=8003
# T4  Consumer Inventory  ← jantung F4d
cd services/inventory; php artisan inventory:consume
```
Consumer harus mencetak: `inventory:consume mendengarkan queue 'inventory.orders'`.

**Seed data uji** (butuh: 1 tenant+outlet, 1 produk ber-resep di Catalog, saldo stok awal di Inventory):
- Di **Catalog**: buat 1 `ingredient` (mis. "Kopi bubuk", unit `g`) + 1 `product`, lalu `recipe`
  produk→ingredient dengan `qty_per_unit` (mis. `18.000`). Cek: `GET /api/recipe?products=<product_id>`
  (header `X-Service-Token: <token>`) balikin resepnya.
- Di **Inventory**: buat `stock_balances` untuk `(outlet_id, ingredient_id)` dengan `qty_on_hand`
  awal yang cukup (mis. `1000.000`), `min_stock` mis. `100.000` (buat menguji `low_stock`).
- Siapkan **JWT cashier** (tenant+outlet **sama** dengan seed) untuk manggil `confirm-payment` —
  lihat 0b (mint manual, bukan lewat register IAM biasa).

> Catat angka awal: `qty_on_hand` bahan itu = **B0**.

### 0b. Siapkan token kasir lewat IAM (bukan lagi mint manual)

> **Berubah 2026-07-26 (F-iam-b).** Dulu bagian ini menyuruh menandatangani token
> dengan klaim di-set tangan, karena IAM belum bisa membuat kasir. Sekarang IAM
> punya endpoint staff, jadi tokennya lahir dari alur yang sama dengan produksi —
> ini juga yang bikin bukti E2E ini lebih berarti: yang diuji token asli, bukan
> token buatan skrip uji.

```bash
# 0b-1. Daftar owner + tenant + outlet default sekaligus. Catat tenant_id & outlet_id
#       dari respons — SEMUA seed (meja, produk, saldo stok) harus memakai dua id ini.
curl -s -X POST http://localhost:8002/api/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"business_name":"Kopi Senja","name":"Vincent","email":"owner@kopisenja.test","password":"Password123","password_confirmation":"Password123"}'

# 0b-2. Owner membuat kasir. Role dipaksa cashier oleh server; kasir mewarisi
#       tenant + outlet owner, jadi id-nya dijamin cocok tanpa disalin tangan.
curl -s -X POST http://localhost:8002/api/staff \
  -H "Authorization: Bearer <ACCESS_TOKEN_OWNER>" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Kasir Satu","email":"kasir1@kopisenja.test","password":"Kasir12345"}'

# 0b-3. Login sebagai kasir → token inilah yang dipakai di langkah 1b.
curl -s -X POST http://localhost:8002/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"kasir1@kopisenja.test","password":"Kasir12345"}'
```

Cek di respons login: `user.role` = `cashier` dan `user.outlet_id` **terisi**. Kalau
`outlet_id` null, seluruh rantai akan gagal di `confirm-payment` (404 isolasi outlet).

**Umur token 15 menit.** Kalau langkah 1b membalas 401, itu bukan bug — login ulang
saja (atau `POST /api/auth/refresh`). TTL pendek itu memang disengaja.

### 0c. Jalan pintas: skrip Path B

Seluruh rantai (register owner -> owner bikin kasir -> produk -> meja -> order
publik -> confirm-payment -> relay -> cek baris `sales` di Finance) tersedia
sebagai satu skrip yang berhenti tepat di link yang putus:

```powershell
powershell -ExecutionPolicy Bypass -File docs\path-b.ps1
```

Memakai consumer **Finance**, jadi tak perlu seed bahan/resep/stok sama sekali.
Aman diulang: tiap run memakai suffix acak (tenant/email/meja baru).

---

## 1. Happy path — satu order, potong sekali

```
# 1a. Buat order (publik). Ganti body sesuai kontrak Ordering; catat "id" order dari respons.
curl -s -X POST http://localhost:8000/api/orders \
  -H 'Content-Type: application/json' \
  -d '{ ...payload order dgn product_id ber-resep, qty=2... }'

# 1b. Konfirmasi bayar sebagai kasir → status PAID → tulis 1 baris outbox order.paid
curl -s -X POST http://localhost:8000/api/cashier/orders/<ORDER_ID>/confirm-payment \
  -H "Authorization: Bearer <JWT_CASHIER>"

# 1c. Relay outbox → publish ke RabbitMQ (satu pass)
cd services/ordering; php artisan outbox:relay --once
```

Consumer (T4) langsung memproses. **Verifikasi di DB `fnbsense_inventory`:**
```sql
-- saldo turun tepat qty_per_unit * item.qty  (mis. 18.000 * 2 = 36.000)
SELECT qty_on_hand FROM stock_balances WHERE ingredient_id = '<ING>';   -- = B0 - 36.000
-- ledger mencatat potong, terikat order
SELECT qty_delta, reason, order_id FROM stock_movements WHERE order_id = '<ORDER_ID>';  -- -36.000, order_deduction
-- pagar idempotensi terisi
SELECT status FROM processed_orders WHERE order_id = '<ORDER_ID>';       -- deducted
```
✅ Lolos kalau ketiganya benar. Kalau `qty_on_hand` **tidak** berubah: cek log consumer —
Catalog unreachable (`requeue`) atau resep kosong (`recipe_missing`) → benahi seed, bukan kode.

---

## 2. Idempoten lintas-restart — backlog tak dobel

```
# 2a. Matikan consumer (Ctrl+C di T4).
# 2b. Buat + confirm-payment order KEDUA (langkah 1a–1b), lalu relay:
cd services/ordering; php artisan outbox:relay --once
```
Pesan sekarang mengendap di queue `inventory.orders` (durable) — belum ada yang mengonsumsi.
Cek RabbitMQ UI: `inventory.orders` punya **1 Ready**.

```
# 2c. Nyalakan lagi consumer:
cd services/inventory; php artisan inventory:consume
```
Backlog diproses **sekali**. Verifikasi order kedua: `qty_on_hand` turun sekali,
`processed_orders` bertambah **satu** baris.

---

## 3. Dedup — event yang sama di-redeliver TIDAK memotong dua kali

Ini inti "potong tepat sekali walau broker at-least-once". Kirim ulang amplop `order.paid`
yang **persis sama** (order_id sama) ke exchange, langsung ke broker:

```
# 3a. Ambil amplop asli yang sudah dipublish, dari DB ordering (bukan bikin manual → dijamin sebentuk):
#     mysql fnbsense_ordering -e "SELECT payload FROM outbox WHERE ...\G"  → salin JSON-nya.

# 3b. Publish ulang JSON itu ke exchange fnbsense.events, routing key order.paid.
#     Lewat tinker Ordering (reuse koneksi & topology yang sudah ada):
cd services/ordering; php artisan tinker
>>> $json = '<PASTE JSON amplop order.paid tadi>';
>>> $conn = \App\Messaging\RabbitMqConnection::open();
>>> $ch = $conn->channel();
>>> $ch->basic_publish(new \PhpAmqpLib\Message\AMQPMessage($json, ['content_type'=>'application/json']), 'fnbsense.events', 'order.paid');
>>> $ch->close(); $conn->close();
```

Consumer menerima lagi, tapi `order_id` sudah ada di `processed_orders` → **ACK & buang**.
Verifikasi: `qty_on_hand` **tidak berubah**, `stock_movements` untuk order itu **tetap 1 baris**,
`processed_orders` **tetap 1 baris**. ✅ Dedup terbukti.

---

## 4. (Opsional) Saga — stok kurang & low-stock

- **Shortfall**: set `qty_on_hand` bahan lebih kecil dari kebutuhan order → bayar → saldo jadi
  **negatif** (bukan di-clamp), consumer menerbitkan `inventory.shortfall`. Cek queue/DLX atau log.
- **Low-stock**: atur order yang membuat saldo melintas dari `> min_stock` ke `<= min_stock` →
  `inventory.low_stock` terbit. Melintas lagi lebih dalam di bahan yang sama → **tak** terbit ulang (#7b).
- **Recipe missing**: order produk yang belum punya resep di Catalog → `recipe_missing`, order tetap
  ter-ACK & `processed` (tak menumbangkan consumer).

---

## Troubleshooting cepat

| Gejala | Kemungkinan | Cek |
|---|---|---|
| Consumer diam saat relay | routing key / exchange beda | RabbitMQ UI: binding `inventory.orders` ← `order.paid` |
| `qty_on_hand` tak berubah, log `requeue` | Catalog down / token salah | `CATALOG_SERVICE_TOKEN` dua sisi, Catalog `:8001` hidup |
| `recipe_missing` tak terduga | produk uji belum ber-resep | `GET /api/recipe?products=<id>` balikin isi |
| relay `nack ... tak dianggap terkirim` | broker tolak publish | vhost/permission user `fnbsense`; exchange durable ada |
| 401 di confirm-payment | JWT salah/kedaluwarsa | mint ulang, `role` cashier/owner, tenant+outlet cocok |
