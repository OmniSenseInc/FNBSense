# Blueprint — F7 Promo Center

Status: **F7a backend selesai dan terverifikasi** (2026-07-23). F7b
Profitability Guard menunggu kontrak COGS. UI Promo Center tetap ditunda sampai
tahap integrasi Dashboard sesuai keputusan proyek.

## Nilai yang dikirim

FNBSense menyediakan template promo global. Owner tidak menulis rumus atau kode:
setelah tenant dan Catalog terisi, owner membuat instance promo untuk outlet aktif,
memilih produk, jadwal, dan nilainya. UI Promo Center akan dipasang ke Dashboard pada
tahap integrasi akhir; F7a menyelesaikan API dan mesin hitungnya terlebih dahulu.

## Kepemilikan

- **Catalog** memiliki template dan definisi promo karena sudah memiliki produk,
  kategori, serta harga dasar.
- **Ordering** tetap pemilik tunggal perhitungan uang final dan snapshot order.
- Dashboard kelak hanya menjadi BFF/UI; tidak menghitung diskon.
- Finance dan Reporting menerima snapshot promo melalui `OrderPaid`.

Tidak dibuat Promotion Service baru pada MVP agar tidak menambah batas service dan
operasional sebelum kebutuhan skalanya terbukti.

## Template MVP

| Key | Fungsi | Parameter utama |
|---|---|---|
| `order_percentage` | Diskon persen dari subtotal order | percentage, min subtotal, max discount |
| `order_fixed` | Potongan nominal order | amount, min subtotal |
| `product_percentage` | Diskon persen produk terpilih | products, percentage, max discount |
| `bundle_fixed_price` | Harga paket untuk kombinasi produk | products + required qty, bundle price |

MVP hanya memakai satu promo per order. Semua kandidat aktif dihitung, lalu diskon
terbesar menang; `priority` menjadi tie-breaker. Stacking dan promo code ditunda agar
hasil harga deterministik dan mudah diaudit.

Endpoint `GET /api/promotion-templates` mengirim metadata `required_fields` dan
`supports`, sehingga UI nantinya dapat membentuk form dari template tanpa menanam
rumus diskon di browser.

## API owner

Semua endpoint berikut berada di Catalog, memerlukan JWT role `owner`, dan otomatis
di-scope ke `tenant_id` serta `outlet_id` pada token:

| Method | Path | Fungsi |
|---|---|---|
| `GET` | `/api/promotion-templates` | Daftar template dan kebutuhan form |
| `GET` | `/api/promotions` | Daftar promo outlet aktif |
| `POST` | `/api/promotions` | Membuat promo berstatus draft |
| `GET` | `/api/promotions/{id}` | Detail promo |
| `PUT` | `/api/promotions/{id}` | Mengubah definisi promo |
| `POST` | `/api/promotions/{id}/activate` | Mengaktifkan atau menjadwalkan promo |
| `POST` | `/api/promotions/{id}/pause` | Menghentikan promo |
| `DELETE` | `/api/promotions/{id}` | Menghapus promo nonaktif |

Contoh draft diskon order 10%:

```json
{
  "name": "Diskon Launching",
  "template": "order_percentage",
  "percentage": 10,
  "min_subtotal": 50000,
  "max_discount": 20000,
  "priority": 100,
  "starts_at": "2026-08-01T00:00:00+07:00",
  "ends_at": "2026-08-31T23:59:59+07:00"
}
```

`tenant_id`, `outlet_id`, harga, dan nominal diskon final tidak diterima dari
browser sebagai sumber kebenaran.

## Lifecycle

```text
Draft -> Active -> Paused
           |
           +-- Scheduled (starts_at belum tiba, status turunan)
           +-- Expired   (ends_at sudah lewat, status turunan)
```

Definisi dapat diubah owner, tetapi setiap order menyimpan snapshot sehingga perubahan
promo tidak mengubah histori.

## Alur hitung

```text
Customer mengirim product_id + qty
  -> Ordering mengambil harga Catalog
  -> subtotal kotor dan item dihitung server-side
  -> Catalog mengevaluasi promo aktif untuk tenant+outlet tepercaya
  -> Ordering memvalidasi hasil, menerapkan discount
  -> service charge dan pajak dihitung dari subtotal setelah discount
  -> order menyimpan gross_subtotal, discount_total, subtotal net,
     promotion_id, dan promotion_snapshot
```

Harga, discount, tenant, dan outlet tidak pernah diterima sebagai nilai final dari
browser.

## Isolasi dan otorisasi

- CRUD promo hanya role `owner`.
- `tenant_id` dan `outlet_id` instance promo berasal dari JWT IAM.
- Produk target divalidasi harus milik tenant JWT yang sama.
- Query CRUD selalu memakai pasangan tenant+outlet; ID tenant lain menghasilkan 404.
- Endpoint evaluasi hanya service-to-service dengan `X-Service-Token`.
- Ordering menurunkan tenant/outlet dari QR meja, bukan request customer.
- Evaluator tetap memfilter definisi promo dengan tenant+outlet tersebut.

## Rollout

Integrasi Ordering memiliki feature flag `PROMOTIONS_ENABLED`. Produksi F7a
mengaktifkannya setelah migration Catalog dan Ordering selesai serta shared service
token terpasang. Saat aktif, kegagalan evaluasi bersifat fail-closed (`503`) agar
customer tidak diam-diam kehilangan promo yang dijanjikan.

## Hasil ke Reporting

Event `order.paid` membawa `gross_subtotal`, `discount_total`, subtotal net, serta
snapshot promo. Reporting menyimpan data itu dan menyediakan:

- tambahan metrik diskon pada `GET /api/summary`;
- `GET /api/promotions/performance?from=YYYY-MM-DD&to=YYYY-MM-DD` untuk jumlah
  transaksi, penjualan kotor, diskon, subtotal net, dan revenue per promo.

Kedua endpoint tetap owner-only dan dibatasi tenant+outlet dari JWT.

## Bukti verifikasi F7a

- Migration Catalog, Ordering, dan Reporting berhasil pada MySQL.
- 9 tes Catalog/37 assertion lulus untuk template, CRUD/lifecycle, isolasi
  tenant+outlet, validasi produk, bundle, best-discount, dan service token.
- 3 tes integrasi order promo/18 assertion dan 8 tes kalkulator/26 assertion lulus.
- Regresi Ordering: 15 tes customer order/51 assertion serta 27 tes kasir dan
  expiry/86 assertion lulus.
- Reporting: 5 tes consumer/14 assertion dan 6 tes analytics/66 assertion lulus,
  termasuk kompatibilitas event lama dan isolasi performa promo.
- Finance: 14 tes consumer/32 assertion lulus; ledger menyimpan subtotal kotor,
  diskon, dan snapshot promo sambil tetap menerima event lama.

## Ditunda

- Promo stacking.
- Voucher/kode promo dan kuota per customer.
- Promo lintas beberapa outlet dari satu akun owner.
- Profitability Guard berbasis COGS.
- UI Promo Center di Dashboard.
