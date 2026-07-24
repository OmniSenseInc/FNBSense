# Blueprint — F6 Reporting & Dashboard

Status: **F6a dan MVP F6b selesai serta terverifikasi** (2026-07-23). Reporting
menggunakan MySQL dan RabbitMQ; Dashboard owner menggunakan Laravel + Filament,
login melalui IAM, dan membaca analytics hanya melalui Reporting API.

## Tujuan

Reporting menyediakan read-model cepat untuk owner tanpa membaca database service lain.
Sumber awalnya adalah event `order.paid`; hanya transaksi `PAID` yang masuk analytics.

## Batas service

- Reporting memiliki database sendiri.
- Reporting tidak membaca database Ordering atau Finance secara langsung.
- Event dikirim minimal sekali, sehingga consumer wajib idempoten.
- Semua query dibatasi oleh `tenant_id` dan `outlet_id` dari JWT.
- Nominal uang tetap integer rupiah.

## Read-model F6a

- `sales_facts`: satu fakta per order PAID untuk metrik omzet/transaksi.
- `product_sales_facts`: agregat item per order untuk leaderboard produk.
- `processed_events`: deduplikasi permanen berdasarkan `event_id`.

Nama produk dapat ikut sebagai snapshot opsional saat transaksi. Consumer tetap menerima
event lama yang hanya membawa `product_id`, sehingga perubahan kontrak bersifat kompatibel.
Jika snapshot belum ada, API tetap mengembalikan `product_id` sebagai identitas utama.

## Endpoint owner

- `GET /api/ping` — health check publik.
- `GET /api/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` — omzet, jumlah transaksi,
  nilai transaksi rata-rata, komposisi metode pembayaran, subtotal kotor,
  diskon, dan subtotal net.
- `GET /api/trends/daily?from=YYYY-MM-DD&to=YYYY-MM-DD` — omzet dan transaksi per hari.
- `GET /api/products/top?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=10` — produk terlaris
  berdasarkan kuantitas, dengan omzet item sebagai informasi pendamping.
- `GET /api/promotions/performance?from=YYYY-MM-DD&to=YYYY-MM-DD` — jumlah
  transaksi, penjualan kotor, diskon, subtotal net, dan revenue per promo.

Semua endpoint laporan memerlukan JWT role `owner` dan `outlet_id`.

## Topologi event

- Exchange: `fnbsense.events` (topic, durable).
- Queue: `reporting.sales` (durable).
- Binding: `order.paid`.
- Pesan rusak/permanen ditolak ke `fnbsense.events.dlx` → `fnbsense.dead`.
- Gangguan database sementara di-requeue.

## Slicing F6

| Slice | Isi | Status |
|---|---|---|
| F6a | Service Reporting, consumer, read-model, API analytics | selesai |
| F6b | Admin Dashboard Laravel + Filament | MVP selesai |
| F6c | Read-model margin/COGS setelah kontrak biaya tersedia | ditunda |

## Bukti verifikasi F6a

- `composer.lock` terbentuk dan autoloader Laravel dapat dijalankan.
- Database MySQL `fnbsense_reporting` dan `fnbsense_reporting_test` tersedia.
- Migration `sales_facts`, `product_sales_facts`, dan `processed_events` berhasil
  pada MySQL.
- 11 test/80 assertion hijau: idempotensi consumer, kompatibilitas event lama,
  payload rusak, JWT owner-only, isolasi outlet, summary, tren harian, top product,
  performa promo, isolasi silang tenant pada seluruh endpoint, dan batas rentang laporan.
- Lima route aktif: `ping`, `summary`, `trends/daily`, `products/top`, dan
  `promotions/performance`.
- E2E RabbitMQ asli lulus: dua delivery event yang identik sama-sama ter-route ke
  `reporting.sales`, queue selesai diproses, tetapi `sales_facts` dan
  `processed_events` masing-masing tetap satu.
- Regresi producer Ordering untuk snapshot `product_name` lulus
  (1 test/15 assertion).

## Hasil F6b

- Panel owner tersedia di `/admin`; root Dashboard mengarah ke panel tersebut.
- Login memakai endpoint IAM dan hanya role `owner` dengan tenant/outlet lengkap
  yang dapat masuk.
- Dashboard tidak membuat user lokal. Profil dan JWT berada di session server,
  bukan `localStorage` browser.
- Request ke Reporting membawa JWT owner, sehingga pembatasan tenant/outlet tetap
  dilakukan oleh Reporting berdasarkan claim token.
- Filter periode mengendalikan kartu omzet/transaksi/AOV, komposisi cash/QRIS,
  grafik omzet harian, dan tabel produk terlaris.
- Logout memanggil revocation token IAM lalu membersihkan session lokal.
- 8 test Dashboard/35 assertion hijau: redirect guest, login IAM owner,
  penolakan non-owner, session kasir/tokennya kedaluwarsa, logout/revocation,
  render data Reporting, dan smoke unit.

Panduan menjalankan dan konfigurasi rinci ada di [DASHBOARD.md](DASHBOARD.md).

## Catatan desain

`OrderPaid` belum membawa COGS. Karena itu F6a tidak mengarang metrik laba/margin.
Laporan arus kas sederhana tetap tersedia di Finance F5, sedangkan Reporting F6a fokus
pada analytics penjualan yang dapat dibuktikan dari event sumber.
