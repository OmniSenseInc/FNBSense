# FNBSense Reporting

Service Laravel F6a yang membentuk read-model analytics dari event `order.paid`.
Service ini tidak membaca database Ordering atau Finance.

Status lokal 2026-07-23: migration berhasil dan 8 test/44 assertion lulus
terhadap MySQL.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan reporting:consume
```

API owner tersedia di `/api/summary`, `/api/trends/daily`, dan `/api/products/top`.
Lihat `docs/REPORTING.md` di root monorepo untuk kontrak lengkap.

## E2E RabbitMQ

Dengan MySQL, Docker, dan RabbitMQ aktif:

```powershell
powershell -ExecutionPolicy Bypass -File tests/E2E/reporting-rabbitmq.ps1
```

Probe memakai `fnbsense_reporting_test`, menjalankan consumer sementara, mengirim
event `order.paid` persisten, lalu memastikan read-model tercatat.
