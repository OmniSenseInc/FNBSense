# Blueprint — Service Catalog (F1)

Status: **belum dimulai** (desain terkunci). Service berikutnya setelah IAM.
Prasyarat: IAM sudah jalan (auth JWT RS256, commit `b6ecd5d`).

## Tujuan
"Apa yang dijual" — sumber kebenaran menu/produk per tenant. Dikonsumsi oleh service Ordering (F2), dashboard, dan customer (public menu).

## Scope — BERTAHAP (hindari over-build)
1. **Tahap awal** (cukup untuk Ordering mulai): `categories` + `products` + harga.
2. **Nyusul**: `product_variants` (mis. Large/Small + delta harga), `addons`/modifier + pivot `product_addon`.
3. **Paling akhir**: BOM/resep (butuh data bahan dari service Inventory).

## Model data (tahap awal)
| Tabel | Kolom inti |
|---|---|
| `categories` | id (UUID), tenant_id (UUID), name, sort_order, is_active, timestamps |
| `products` | id (UUID), tenant_id (UUID), category_id (UUID, nullable), name, description, price (decimal), is_available (bool), image_url (nullable), timestamps |

Semua query **wajib difilter `tenant_id`** dari JWT (isolasi tenant).

## Endpoint
**Owner/manager — JWT IAM, middleware `role:owner`:**
- `GET/POST /api/categories`, `PUT/DELETE /api/categories/{id}`
- `GET/POST /api/products`, `PUT/DELETE /api/products/{id}`

**Publik (dikonsumsi customer & Ordering):**
- `GET /api/menu` → kategori + produk aktif milik tenant (butuh identifikasi tenant, mis. via header/subdomain/param — putuskan saat implementasi).

## Auth lintas-service (PENTING — pola pertama kali dipakai)
Catalog **verifikasi JWT pakai PUBLIC key IAM** (tidak pegang private key, tidak query IAM). Ini implementasi konkret arsitektur RS256 (lihat [[lessons-iam-architecture]]).
Langkah:
1. Copy `jwt-public.pem` IAM ke Catalog (atau shared volume/secret).
2. Set `JWT_ALGO=RS256` + `JWT_PUBLIC_KEY` (public saja) di `.env` Catalog.
3. Guard `api` (jwt) baca `tenant_id`/`role` dari custom claims token — TANPA panggil IAM.
4. Middleware `role:owner` untuk endpoint tulis (lihat matriks `docs/RBAC.md`).
Pasang juga `ForceJsonResponse` middleware (lihat [[lessons-laravel-api-401]]) sejak awal.

## Urutan implementasi (saran, satu file per langkah — mode Vincent)
1. Scaffold Laravel `services/ordering`… → **`services/catalog`** (composer create-project).
2. Install jwt-auth + set RS256 (public key) + ForceJson + guard api.
3. Model + migration: Category, Product.
4. Middleware `role` (baca claim role dari JWT).
5. Controller CRUD categories + products (filter tenant_id, role:owner).
6. Route publik `GET /api/menu`.
7. Feature test (pola dari `services/iam/tests/Feature/AuthTest.php`).

## Catatan
- DB terpisah: `fnbsense_catalog` (+ `fnbsense_catalog_test` untuk test).
- Reuse pola IAM: UUID (HasUuids), ForceJson, exception handler JWT→401, test pakai MySQL DB terpisah.
