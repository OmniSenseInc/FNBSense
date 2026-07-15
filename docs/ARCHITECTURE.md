# FNBSense — Arsitektur

## Prinsip

1. **Full microservice** untuk fault isolation: satu service down tidak menghentikan yang lain.
2. **Database per service** — tidak ada service yang membaca DB service lain langsung. Integrasi lewat API (sync) atau event (async).
3. **Event-driven untuk konsistensi keuangan.** Karena data tersebar, invarian `PAID → stok → laporan` dijaga lewat event + idempotensi, bukan transaksi DB tunggal.

## Komunikasi

- **Sync**: klien → **API Gateway (Traefik)** → service. Untuk query real-time (mis. ambil menu, buat order).
- **Async**: service → **RabbitMQ** → service. Untuk propagasi kejadian bisnis (mis. `OrderPaid`).

## Service & kepemilikan data

| Service | Runtime | Data yang dimiliki |
|---|---|---|
| IAM | Laravel | user, role, permission, tenant, outlet |
| Catalog | Laravel | kategori, produk, variant, addon, resep/BOM |
| Ordering | Laravel | meja, QR, sesi, order, order item, status bayar |
| Inventory | Laravel | bahan, saldo stok, pergerakan, min-stok |
| Finance | Laravel | penjualan PAID, COGS, pajak, service, expense, shift |
| Reporting | Laravel | read-model (leaderboard, trend, margin) |
| Printing | Laravel | print job/queue |
| Notification | Node | notif low-stock, WhatsApp |
| Realtime | Node | koneksi WebSocket (KDS) |
| Hermes | Python | — (read-only via API/Reporting) |
| Admin Dashboard | Laravel + Filament | read-model + orchestrasi API |

## Invarian keuangan & pola menjaganya

- **Aturan:** hanya status `PAID` yang memicu pengurangan stok dan pencatatan laporan. `PENDING/REPORTED/EXPIRED/CANCELLED` tidak berdampak.
- **Owner status:** service **Ordering** adalah satu-satunya yang mengubah status order.
- **Transactional Outbox:** saat kasir mengonfirmasi PAID, Ordering menulis perubahan status + baris outbox dalam satu transaksi lokal, lalu relay mem-publish `OrderPaid` ke RabbitMQ.
- **Consumer idempotent:** Inventory & Finance memproses `OrderPaid` sekali saja (dedup by `order_id`). Order PAID tidak boleh diproses dua kali.
- **Saga / kompensasi:** bila Inventory menemukan stok tak cukup, terbitkan event kegagalan + alert untuk koreksi manual. Uang sudah masuk → tidak ada rollback diam-diam atas PAID.

## Alur transaksi (QRIS statis)

```
QR meja → Pesanan → Checkout (total+service+PPN, QRIS statis)
   → Lapor bayar (PAYMENT_REPORTED) → Verifikasi kasir → PAID
   → [event OrderPaid] → Inventory (potong stok) + Finance (catat) + Printing (tiket/struk) + Realtime (KDS)
```

## Roadmap per fase

- **F0** Fondasi infra: monorepo, docker-compose (Traefik/RabbitMQ/Redis), IAM, kontrak event.
- **F1** Catalog + CRUD (Filament BFF).
- **F2** Ordering + public menu (state machine bayar, Outbox → `OrderPaid`).
- **F3** Realtime (Node) + Printing.
- **F4** Inventory (consume `OrderPaid`, deduksi BOM idempotent, saga stok).
- **F5** Finance + shift.
- **F6** Reporting + dashboard.
- **F7** Promo & bundle + Profitability Guard.
- **F8** Notification (Node) + Hermes (Python).
- **F9** Hardening: acceptance & resilience test, backup, monitoring, security review.
