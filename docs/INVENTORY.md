# Blueprint — F4 Inventory (consume `order.paid` → potong stok)

Status: **desain terkunci** (disetujui Vincent 2026-07-19). Belum ada kode.
Prasyarat: Ordering menerbitkan `order.paid` ke RabbitMQ (F3a, `a66f9a5`); exchange
`fnbsense.events` (topic) + DLX `fnbsense.events.dlx` sudah ada & idempoten di-assert.
Lihat [[project-status]], [ARCHITECTURE.md](ARCHITECTURE.md), [REALTIME.md](REALTIME.md), [CATALOG.md](CATALOG.md).

## Keputusan arsitektur (dipilih Vincent 2026-07-19)

**Resep & bahan master di Catalog; Inventory hanya pegang saldo stok.**
Aliran data **satu arah Catalog → Inventory**. Owner input bahan + resep di layar Catalog.
Inventory konsumsi `order.paid`, **query resep ke Catalog via REST** (opsi C), lalu potong saldo.

Kenapa begini (bukan resep-di-Inventory): resep = jembatan produk→bahan. Menaruh bahan +
resep di Catalog membuat `ingredient_id` lahir bersama produk (satu sumber, tak ada drift),
dan Inventory cukup jadi "buku saldo" per bahan. Konsekuensinya (di bawah) diterima sadar.

## Tujuan

Mewujudkan invarian uang **`PAID → stok`**: setiap `order.paid` mengurangi saldo bahan
sesuai resep Catalog, **tepat sekali** (idempotent). Uang sudah masuk saat event terbit →
**tidak ada rollback**. Stok kurang bukan alasan membatalkan penjualan; dicatat jujur +
memicu alert koreksi manual (saga). Inventory **consumer**, bukan sumber kebenaran order.

## Keputusan terkunci

| # | Keputusan | Alasan |
|---|---|---|
| 1 | **Bahan (`ingredients`) + resep (`recipes`) master di Catalog.** Inventory **tak** menyimpan nama/unit bahan maupun resep — hanya **saldo** per `ingredient_id`. | Satu sumber kebenaran BOM, tak ada drift. `ingredient_id` lahir di Catalog bersama produk → aliran satu arah, tak melingkar. |
| 2 | Inventory = **Laravel + MySQL `fnbsense_inventory`**. Auth REST **stateless RS256 public-key-only** (pola Catalog/Ordering) untuk CRUD stok owner. | Konsisten pola lintas-service mapan. |
| 3 | Consumer = **daemon PHP** `php artisan inventory:consume` (php-amqplib, sama seperti relay Ordering). Queue **`inventory.orders`** durable, bind rk `order.paid` ke `fnbsense.events`, `x-dead-letter-exchange: fnbsense.events.dlx`. Prefetch 1–10. | Reuse stack AMQP relay. DLX sejak awal (queue immutable). |
| 4 | **Resep diambil dari Catalog via HTTP** saat konsumsi — reuse pola `CatalogClient` (Ordering L5): timeout 3s, gagal → exception. Endpoint batch `GET /api/recipe?products=<id,id,...>`. | Opsi C. Batch = 1 call per order, bukan per item. Pola client sudah terbukti di Ordering. |
| 5 | **Idempotensi bisnis by `order_id`.** Tabel `processed_orders` `unique(order_id)`, di-insert di transaksi yang sama dengan potong stok. Order_id sudah ada → ACK & buang. | Satu order PAID hanya potong stok **sekali**, meski broker redeliver (at-least-once). Pagar di DB, bukan cuma kode. Tipe exception ditangkap **persis** ([[lessons-exception-type-specificity]]). |
| 6 | **Stok = ledger.** `stock_movements` append-only (signed `qty_delta`, `reason`, `order_id`). Saldo `qty_on_hand` di `stock_balances` = materialized, `lockForUpdate` saat potong. | Jalur uang wajib auditable & bisa direkonstruksi. Restock/opname = movement juga. |
| 7 | **Stok kurang → potong tetap jalan, saldo boleh negatif**, terbitkan `inventory.shortfall`. Tak clamp 0, tak rollback PAID. | Produk sudah terjual & dimasak → stok fisik memang berkurang. Negatif = sinyal jujur "utang stok, perlu opname". |
| 7b | **(F4c) Event saga terbit saat MELINTAS ambang, bukan saat berada di bawahnya.** `shortfall` hanya kalau `before >= 0 && after < 0`; `low_stock` hanya kalau `min_stock > 0 && before > min_stock && after <= min_stock`. Saldo yang sudah minus tak diteriakkan ulang. | Tanpa ini, satu bahan habis = satu alert per order (puluhan kembar) → owner mematikan notifikasi & fitur jadi sampah. `before`/`after` sudah di tangan dalam transaksi yang sama → nol state, nol tabel throttle. Konsekuensi diterima: minus yang makin dalam tak teriak lagi; eskalasi = urusan F8. |
| 7c | **(F4c) `processed_orders.status` tetap dari KONDISI akhir (`after < 0`), bukan dari melintas.** | Tabel mencatat keadaan order, event mencatat kejadian baru. Order kedua di bahan yang sudah minus tetap ber-status `shortfall` walau tak menerbitkan event. |
| 8 | **Catalog unreachable saat konsumsi → `nack(requeue)`, JANGAN tandai processed** → retry saat Catalog up. Gagal berulang (N kali) → DLQ. | Ini mitigasi kelemahan opsi C: `PAID→stok` tetap terjaga, potong cuma **tertunda**, bukan hilang. Consumer yang ragu memilih retry, bukan tandai-selesai. |
| 9 | **Produk tanpa resep di Catalog (resep kosong) → skip + `inventory.recipe_missing`, order tetap di-ACK & processed.** Beda dari Catalog-down (#8). | Menu laku tak boleh menumbangkan consumer karena owner belum isi resep. Pola [[lessons-external-price-boundary]]. "Catalog jawab tapi resep kosong" ≠ "Catalog tak menjawab". |
| 10 | Resep **level `product_id` saja** dulu. `variant_id` & `addons` amplop **diabaikan** untuk deduksi (utang). | Catalog belum punya variant/addon. YAGNI. |

## Skema Inventory (MySQL `fnbsense_inventory`)

Inventory **tak** punya tabel `ingredients`/`recipes` (itu di Catalog). `ingredient_id` di sini
= UUID milik Catalog, **tanpa FK** (lintas-DB, pola Catalog/Ordering).

```
stock_balances                  ← saldo materialized per bahan per outlet
  id            uuid pk
  tenant_id     uuid
  outlet_id     uuid            (stok fisik per outlet)
  ingredient_id uuid            (milik Catalog, tanpa FK)
  qty_on_hand   decimal(14,3)   default 0
  min_stock     decimal(14,3)   default 0   (ambang low-stock; DIPAKAI F4c — 0 = owner belum set → diam)
  timestamps
  unique(outlet_id, ingredient_id)

stock_movements                 ← append-only ledger
  id            uuid pk
  tenant_id     uuid
  outlet_id     uuid
  ingredient_id uuid
  order_id      uuid  null      (null = manual opname/restock)
  qty_delta     decimal(14,3)   (signed: negatif = potong)
  reason        enum(order_deduction, manual_adjust, restock)
  occurred_at   datetime
  created_by    uuid  null      (user; null = event)
  timestamps

processed_orders                ← pagar idempotensi
  order_id      uuid pk
  tenant_id     uuid
  outlet_id     uuid
  status        enum(deducted, shortfall, recipe_missing)
  processed_at  datetime
```

## Prasyarat di Catalog (F4-pre — kerjaan service Catalog)

Karena resep di Catalog, Catalog harus dibangun dulu:

```
ingredients (Catalog, master bahan)
  id uuid pk, tenant_id uuid, name string, unit enum(g,ml,pcs)
  unique(tenant_id, name)

recipes (Catalog)
  id uuid pk, tenant_id uuid, product_id FK→products, ingredient_id FK→ingredients
  qty_per_unit decimal(14,3)  CHECK > 0
  unique(product_id, ingredient_id)
```

Endpoint **`GET /api/recipe?products=<uuid,uuid,...>`** →
`[{ product_id, ingredients: [{ ingredient_id, qty_per_unit, unit }] }]`.
CRUD `ingredients`/`recipes` owner-only (pola Catalog). Produk tanpa resep → array kosong.

**Auth Inventory→Catalog (dikunci): shared-secret header.** Consumer jalan dari event, tak
punya user JWT. Inventory kirim `X-Service-Token: <secret>` (dari env, sama dua sisi); Catalog
validasi via middleware ringan + tetap scope `tenant_id` dari query. Alasan: Inventory tak
pegang private key IAM → mint JWT over-build. Naik ke service-JWT kalau perlu lebih kuat nanti.

## Kontrak consumer `order.paid` (WAJIB)

1. **Validasi amplop**: `event_id`, `event_type == "order.paid"`, `tenant_id`, `outlet_id`,
   `payload.order_id`, `payload.items[]`. Tak berbentuk → `nack(requeue=false)` → DLQ. Jangan crash.
2. **Dedup**: `processed_orders` sudah punya `order_id` → **ACK & buang**.
3. **Ambil resep**: kumpulkan `product_id` unik dari items → 1 call
   `GET /api/recipe?products=...`. **Catalog unreachable/timeout/5xx → `nack(requeue)`, STOP,
   jangan tandai processed** (#8). Retry belakangan.
4. **Satu `DB::transaction`**:
   a. `INSERT processed_orders(order_id)`. Unique violation (race) → rollback + ACK.
   b. Per item: dari resep Catalog, per ingredient → `qty_delta = -(qty_per_unit * item.qty)`,
      `lockForUpdate` `stock_balances(outlet_id, ingredient_id)` (buat baris saldo 0 jika belum
      ada), tulis `stock_movements(order_deduction, order_id)`, kurangi `qty_on_hand`.
      Saldo `< 0` → kumpulkan `shortfall`.
      Produk yang resepnya kosong dari Catalog → kumpulkan `unmapped`.
   c. `processed_orders.status` = `recipe_missing` (ada unmapped) / `shortfall` (ada negatif) /
      `deducted` (normal).
5. **Commit.** Lalu di luar transaksi: `unmapped` → `inventory.recipe_missing`; bahan yang
   **baru** melintas ke minus → `inventory.shortfall`; yang **baru** melintas `min_stock` →
   `inventory.low_stock` (#7b). Log **warning** (minim PII). Gagal publish → `Log::error` &
   **tetap ACK**: potong sudah commit, requeue percuma (bakal ke-dedup). **ACK.**
6. **Gagal transient DB** → rollback → `nack(requeue)`. Jangan tandai processed saat ragu.

Baris `processed_orders` ditulis **di dalam** transaksi potong → commit atomik: potong sukses
⟺ tertandai processed.

## Saga stok kurang

- PAID → **tak ada rollback**. Potong tercatat penuh, saldo boleh negatif.
- `inventory.shortfall` (amplop event baru): `order_id`, `outlet_id`,
  `[{ingredient_id, needed, on_hand_after}]` → dipantau/alert (F8; F4 cukup terbitkan + log).
- `inventory.low_stock` — peringatan **dini** (masih sempat belanja), lawan dari `shortfall`
  yang sudah telat. Tak terbit kalau bahan yang sama sekaligus jebol ke minus pada order itu:
  `shortfall` sudah kabar yang lebih parah.
- Skema ketiganya di `shared/contracts/events/inventory-*.event.json`.
- Koreksi = restock/opname manual (movement `restock`/`manual_adjust`), bukan batalkan order.

## Skrutini keamanan (jalur uang → wajib)

1. **Isolasi tenant+outlet**: saldo/movement di-scope `outlet_id` amplop; query resep di-scope
   `tenant_id` amplop. Jangan potong stok tenant/outlet lain, jangan bocorkan resep lintas-tenant.
2. **Consumer tak percaya pesan mentah** → validasi bentuk (poin 1).
3. **Idempotensi = pertahanan uang**: `unique(order_id)`. Dobel-potong = kebocoran.
4. **Catalog-down, shortfall, recipe_missing tak boleh hilang senyap** → nack/retry atau event+log;
   malformed → DLQ.
5. **Kredensial broker/DB + secret service-auth Catalog dari env**, tak hardcode.
6. **CRUD stok owner-only**, di-scope `tenant_id` token, anti-IDOR → 404.
7. **Stok negatif = sinyal**, bukan error ditelan.

## Slicing F4 (satu langkah = satu review — mode Vincent)

| Sub | Isi | Definisi selesai |
|---|---|---|
| **F4-pre** | **(Catalog)** tabel `ingredients`+`recipes`, CRUD owner-only, endpoint `GET /api/recipe?products=`. | Owner input bahan+resep di Catalog; endpoint balikin resep batch; test scoping/IDOR hijau. |
| **F4a** | Scaffold `services/inventory` (Laravel+MySQL) + auth RS256 + skema (3 tabel) + CRUD saldo/opname (restock, adjust) owner-only. | Migrasi jalan; owner bisa restock/opname via REST; movement tercatat; test scoping hijau. |
| **F4b** | Consumer `inventory:consume` + `CatalogRecipeClient` (kirim `X-Service-Token`) + deduksi idempotent + `processed_orders`. | `order.paid` → saldo turun sesuai resep Catalog; replay tak dobel; Catalog-down → retry (bukan hilang); test bergigi (mutasi hapus unique/lock → merah). |
| **F4c** | Saga: `inventory.shortfall` + `inventory.recipe_missing` + `inventory.low_stock` (skema JSON di `shared/contracts/`) via `EventPublisher`; terbit saat melintas ambang (#7b); saldo negatif jujur; resep kosong tak menumbangkan. | Stok kurang → saldo negatif + event + ACK; resep kosong → skip + event + ACK; lintas `min_stock` → peringatan dini; **jebol kedua kali di bahan sama → TAK terbit lagi**. |
| **F4d** | Bukti E2E: `confirm-payment` → relay → consumer → saldo turun; matikan consumer, bayar lagi, nyalakan → backlog terproses tanpa dobel. | Potong sekali per order, idempoten lintas-restart. |

## Utang yang diakui sejak awal

- **Auth Inventory→Catalog** = shared-secret header (`X-Service-Token`) — pola baru di repo, biaya nyata opsi C.
- **Catalog jadi dependensi runtime jalur uang** — dimitigasi nack/retry (#8), bukan dihilangkan.
- **Variant/addon belum dipotong** (#10) — menyusul saat Catalog punya variant.
- ~~**`inventory.shortfall`/`inventory.recipe_missing`** belum ada di `shared/contracts/`~~ — **lunas F4c**, plus `inventory.low_stock`.
- **Event saga publish langsung, bukan lewat outbox.** Crash tepat di sela commit & publish → alert
  hilang (kondisinya masih terbaca dari `processed_orders` + saldo, jadi bisa direkonsiliasi query).
  Sadar dipilih: outbox = +1 tabel +1 daemon untuk alert, bukan jalur uang. Naikkan kalau F8 butuh garansi kirim.
- **Produk tanpa resep sebaiknya dicegah di hulu** — Catalog melarang produk tanpa resep diaktifkan.
  `recipe_missing` tetap perlu sebagai jaring pengaman, tapi hulunya yang menutup lubang. Slice Catalog, belum dijadwal.
- **Variant/addon** masih belum dipotong (#10).
- **Alert nyata** ke owner = F8 Notification; F4 cukup terbitkan event + log.
- **`JWT_PUBLIC_KEY` path** — reuse pola path relatif (utang Windows lintas-service) saat scaffold auth.
