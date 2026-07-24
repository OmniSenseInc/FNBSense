# FNBSense Dashboard

Dashboard owner F6b berbasis Laravel dan Filament. Service ini berperan sebagai
BFF (backend-for-frontend): login diteruskan ke IAM dan data visual diambil dari
Reporting API. Dashboard tidak memiliki tabel user maupun membaca database service
lain secara langsung.

## Menjalankan lokal

Pastikan IAM berjalan di port `8000` dan Reporting di port `8005`, lalu:

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan serve --port=8006
```

Buka `http://127.0.0.1:8006/admin` dan masuk menggunakan akun IAM dengan role
`owner`.

Konfigurasi service:

```dotenv
IAM_URL=http://127.0.0.1:8000
REPORTING_URL=http://127.0.0.1:8005
SESSION_DRIVER=file
```

## Verifikasi

```bash
vendor/bin/pint --test
php artisan test
php artisan route:list --path=admin
```

Dokumentasi arsitektur dan batas keamanan: [../../docs/DASHBOARD.md](../../docs/DASHBOARD.md).
