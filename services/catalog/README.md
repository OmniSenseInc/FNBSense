# Service Catalog (FNBSense)

Sumber kebenaran **"apa yang dijual"** — menu/produk per tenant. Dikonsumsi service Ordering, dashboard, dan customer (menu publik).

Laravel 13 · MySQL (`fnbsense_catalog`) · PHP 8.3+. Auth **stateless RS256**: Catalog memverifikasi JWT terbitan IAM pakai *public key* saja — tidak menerbitkan token, tidak query IAM.

## Setup
```bash
composer install
cp .env.example .env
php artisan key:generate
# copy public key IAM:
#   storage/keys/jwt-public.pem  <-  services/iam/storage/keys/jwt-public.pem
php artisan migrate
php artisan serve --port=8001
```

## Auth lintas-service
- `JWT_ALGO=RS256`, hanya `JWT_PUBLIC_KEY` di `.env` (public key IAM). Tanpa private key.
- Middleware `jwt` (`AuthenticateJwt`) verifikasi tanda tangan token, ekspos `tenant_id`/`role`/`user_id` dari klaim ke request — tanpa DB/IAM.
- Middleware `role:owner` (`EnsureRole`) batasi endpoint tulis.
- `JWT_BLACKLIST_ENABLED=false` (logout domain IAM; lihat [SECURITY_TODO.md](SECURITY_TODO.md)).

## Endpoint
| Method | Path | Akses |
|---|---|---|
| GET | `/api/ping` | publik (health) |
| GET | `/api/menu?tenant=<uuid>` | publik (customer/Ordering) |
| GET·POST | `/api/categories` | `jwt` + `role:owner` |
| PUT·DELETE | `/api/categories/{id}` | `jwt` + `role:owner` |
| GET·POST | `/api/products` | `jwt` + `role:owner` |
| PUT·DELETE | `/api/products/{id}` | `jwt` + `role:owner` |
| GET | `/api/promotion-templates` | `jwt` + `role:owner` |
| GET·POST | `/api/promotions` | `jwt` + `role:owner` |
| GET·PUT·DELETE | `/api/promotions/{id}` | `jwt` + `role:owner` |
| POST | `/api/promotions/{id}/activate` | `jwt` + `role:owner` |
| POST | `/api/promotions/{id}/pause` | `jwt` + `role:owner` |

Semua query promo di-scope `tenant_id` dan `outlet_id` dari token. Definisi promo
dan engine hitung F7a dijelaskan di [docs/PROMOTIONS.md](../../docs/PROMOTIONS.md).
`tenant_id` = referensi logis ke IAM (UUID, tanpa FK lintas-DB).

## Test
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS fnbsense_catalog_test"
php artisan test
```
Feature test menandatangani token dengan *private key* IAM lalu memverifikasi lewat *public key* Catalog — menguji alur RS256 lintas-service sungguhan (bukan mock).
