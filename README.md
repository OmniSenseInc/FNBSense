# FNBSense

**Operating system untuk cafe/F&B** — menyatukan order meja, kasir, dapur, stok, keuangan, dan asisten AI owner ke satu ekosistem. Dibangun sebagai **microservice**.

> Positioning: bukan sekadar web kasir. Setiap kejadian bisnis (order, pembayaran, stok, laporan) mengalir dari sumber yang konsisten lewat event antar-service.

## Arsitektur (ringkas)

Full microservice, komunikasi **sync** via API Gateway (Traefik) dan **async** via message broker (RabbitMQ). Lihat detail di [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

| Service | Runtime | Tanggung jawab |
|---|---|---|
| API Gateway (Traefik) | – | Routing, entry tunggal, TLS |
| IAM | Laravel | Auth, tenant, RBAC |
| Catalog | Laravel | Produk, variant, addon, BOM |
| Ordering | Laravel | Meja/QR, order, state machine bayar (owner transaksi) |
| Inventory | Laravel | Stok, deduksi BOM |
| Finance | Laravel | Penjualan PAID, COGS, expense, shift |
| Reporting | Laravel | Read-model & analytics |
| Printing | Laravel | Print queue (Print Bridge lokal) |
| Notification | Node | Low-stock push, WhatsApp |
| Realtime | Node | WebSocket / KDS |
| Hermes | Python | Asisten Telegram (read-only) |
| Admin Dashboard | Laravel + Filament | UI owner/manager di atas read-model |

## Invarian kritis

- Hanya order berstatus **`PAID`** yang mengurangi stok & masuk laporan.
- Karena microservice, konsistensi keuangan bersifat **eventual**: Ordering (owner status) → **Transactional Outbox** → event `OrderPaid` → Inventory & Finance consume **idempotent**. Kegagalan stok → **Saga/alert**, bukan rollback diam-diam.

## Struktur repo (monorepo)

```
FNBSense/
├── docker-compose.yml      # infra dev: Traefik, RabbitMQ, Redis
├── infra/                  # konfigurasi gateway & infra
├── shared/contracts/       # skema event antar-service (source of truth kontrak)
├── services/               # satu folder per microservice
└── docs/                   # arsitektur & keputusan
```

## Menjalankan (dev)

Prasyarat: Docker + Docker Compose, PHP 8.x, Composer, Node, Python.

```bash
cp .env.example .env
docker compose up -d          # start Traefik, RabbitMQ, Redis
```

- RabbitMQ management UI: http://localhost:15672
- Traefik dashboard: http://localhost:8080

## Roadmap

Dikembangkan bertahap per fase (F0 infra → F1 Catalog → F2 Ordering → … → F9 Hardening). Detail di [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).
