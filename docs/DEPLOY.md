# Deploy produksi — jalur kritis

Runbook untuk menaikkan FNBSense ke satu VPS di balik HTTPS. Dijalankan dari
VPS, bukan dari laptop.

**Cakupannya sengaja tidak lengkap.** Yang naik di sini cuma yang dibutuhkan
sebuah kafe untuk berjualan: IAM, Catalog, Ordering, Inventory, Notification,
dua app frontend, MySQL, RabbitMQ, Redis, dan empat daemon. Finance, Reporting,
Dashboard, dan Realtime sudah jadi dan berjalan, tapi kafe bisa menerima
pesanan pertamanya tanpa mereka — dan tiap container tambahan satu lagi yang
harus benar sebelum itu terjadi.

---

## 0. Prasyarat

| Hal | Kenapa |
|---|---|
| VPS Linux, Docker + Compose v2 terpasang | Semuanya berjalan sebagai container |
| **Dua** nama domain | `DOMAIN_CUSTOMER` (HP pelanggan) dan `DOMAIN_STAFF` (kasir & dapur) |
| A-record kedua domain sudah menunjuk ke IP VPS | Let's Encrypt memverifikasinya lewat port 80 **saat compose menyala** — kalau DNS belum menyebar, sertifikat gagal terbit |
| Port 80 dan 443 terbuka ke internet | 443 untuk lalu lintas, 80 untuk tantangan ACME dan pengalihan ke https |

Domain **tak bisa diganti alamat IP**: Let's Encrypt tak menerbitkan sertifikat
untuk IP, dan alamat pelanggan ikut tercetak ke dalam QR yang ditempel di meja.
Stiker membekukan alamat — IP yang bisa berubah adalah bom waktu di sana.

---

## 1. Ambil kode

```bash
git clone <repo> fnbsense
cd fnbsense
```

## 2. Bangkitkan kunci JWT

Satu pasang untuk seluruh sistem. IAM yang menandatangani, empat service lain
hanya memverifikasi.

```bash
openssl genrsa -out infra/keys/jwt-private.pem 2048
openssl rsa -in infra/keys/jwt-private.pem -pubout -out infra/keys/jwt-public.pem

# Container berjalan sebagai www-data (uid 82), bukan root. Tanpa ini, kunci
# yang dimiliki root tak terbaca dan SEMUA login gagal dengan pesan yang tak
# menyebut berkas sama sekali.
sudo chown 82:82 infra/keys/*.pem
chmod 400 infra/keys/*.pem
```

`infra/keys/` sudah masuk `.gitignore`. Private key ini yang menerbitkan
seluruh token — siapa pun yang memegangnya bisa menjadikan dirinya owner di
kafe mana pun.

## 3. Isi kredensial

```bash
cp .env.production.example .env.production
```

Buka dan isi semuanya. Yang tak boleh diketik sendiri (pakai keluaran acak):

```bash
openssl rand -base64 24          # MYSQL_ROOT_PASSWORD, MYSQL_PASSWORD, RABBITMQ_PASSWORD
openssl rand -hex 32             # CATALOG_SERVICE_TOKEN
echo "base64:$(openssl rand -base64 32)"   # APP_KEY_*, lima kali, HARUS berbeda
```

Compose menolak menyala kalau ada yang kosong. Itu disengaja — nilai default
yang diam-diam terpakai di produksi adalah cara paling sunyi memasang sandi
`secret`.

## 4. Nyalakan

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

Build pertama lama (lima image PHP, dua image Node). Sesudahnya lapisan
dependensi dipakai ulang selama `composer.lock` / `package-lock.json` tak
berubah.

Pantau sampai semuanya `healthy`:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production ps
```

## 5. Migrasi

Database dibuat otomatis oleh `infra/mysql/init.sql`, tapi **tabelnya tidak**.
Jalankan sekali, per service:

> **Sebelum langkah ini, `ordering-relay` akan restart berulang-ulang — itu
> normal.** Ia mencari tabel `outbox` yang belum dibuat dan mati dengan
> `Base table or view not found`. Terhitung 17 restart saat uji lokal sebelum
> migrasi dijalankan. Begitu migrasi selesai ia pulih sendiri (`restart:
> unless-stopped`), tanpa perlu disentuh. Jangan menghabiskan waktu men-debug
> daemon di antara langkah 4 dan 5.

```bash
for s in iam catalog ordering inventory notification; do
  docker compose -f docker-compose.prod.yml --env-file .env.production \
    exec "$s" php artisan migrate --force
done
```

Sengaja manual, bukan dijalankan otomatis saat container menyala: migrasi yang
ikut tiap restart akan balapan sendiri begitu container di-restart bersamaan,
dan yang rusak karenanya adalah data — bukan sesuatu yang bisa diulang.

## 6. Buat owner pertama

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production \
  exec iam php artisan tinker
```

Atau lewat `POST https://$DOMAIN_STAFF/iam/auth/register`. Sesudah owner
pertama ada, staf berikutnya dibuat dari layar setelan.

## 7. Tutup sisanya

MySQL, RabbitMQ, dan Redis **tidak** memetakan port ke host di
`docker-compose.prod.yml` — mereka hanya terjangkau container tetangga lewat
jaringan `fnbsense`. Firewall tetap dipasang sebagai lapis kedua:

```bash
sudo ufw default deny incoming
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

Dasbor Traefik tidak dinyalakan di produksi. Di `docker-compose.yml` (dev) ia
terbuka tanpa sandi di 8080, dan isinya peta lengkap seluruh service.

## 8. Verifikasi

```bash
curl -I https://$DOMAIN_CUSTOMER          # 200, sertifikat sah
curl -I http://$DOMAIN_CUSTOMER           # 301 ke https
curl -s https://$DOMAIN_STAFF/iam/up      # "OK" dari Laravel
curl -s https://$DOMAIN_CUSTOMER/catalog/up
```

Lalu dari HP sungguhan: pindai QR meja → menu termuat → pesan → kasir melihatnya
di antrean → konfirmasi bayar → pesanan muncul di `/dapur`.

Rantai itu satu-satunya bukti yang sahih. Kelima service bisa `healthy`
sekaligus sementara relay tak menyambung ke broker, dan tak ada satu pun
healthcheck yang menyadarinya.

---

## Yang perlu diketahui saat merawatnya

**Nilai `VITE_*` ikut terpanggang ke dalam bundle JavaScript.** Mengubah nama
kafe atau domain pelanggan menuntut `up -d --build`, bukan `restart`. Yang
paling mahal kalau lupa adalah `VITE_CUSTOMER_URL`: ia berakhir di dalam QR
yang sudah tercetak dan tertempel di meja.

**Rute Traefik meniru persis proxy di `apps/*/vite.config.ts`** — awalan yang
sama (`/iam`, `/ordering`, `/catalog`, `/notification`), pemotongan awalan yang
sama. Karena itu tak ada satu baris pun kode frontend yang berubah antara dev
dan produksi, dan bagi browser semuanya tetap satu origin.

Itu juga yang membuat CORS tak pernah ikut bermain — dan itu satu-satunya
sebab ia tak menyakiti kita selama ini. Tak ada satu pun service di repo ini
yang punya `config/cors.php` sendiri, jadi yang berlaku adalah bawaan
framework: `paths: ['api/*']` dengan `allowed_origins: ['*']`. Selama semua
lewat satu origin, aturan itu tak pernah dipakai. Begitu backend dipindah ke
domainnya sendiri, ia langsung jadi izin terbuka untuk situs mana pun.

**Ordering punya dua router, satu service.** Pelanggan memesan dari domainnya,
kasir mengonfirmasi dari domainnya, keduanya sampai ke container yang sama.

**Prioritas rute dibiarkan otomatis.** Traefik mendahulukan aturan yang lebih
panjang, dan `Host(x) && PathPrefix(/iam)` lebih panjang daripada `Host(x)`
milik app. Kalau suatu saat app frontend justru yang menjawab `/iam`, di sinilah
sebabnya.

**Inventory belum punya rute HTTP.** Layar stok kasir belum tersambung, jadi
tak ada yang memanggilnya; yang bekerja sekarang cuma consumer-nya. Rutenya
ditambahkan saat layar itu dibuat.

**`schedule:work` adalah container tersendiri.** Tanpa ia, `orders:expire` tak
pernah jalan: pesanan menumpuk sebagai PENDING selamanya dan hitung mundur di
kartu kasir menghitung ke tenggat yang tak pernah tiba.

**`DB_DATABASE` Notification ditulis eksplisit di compose.** Default di
`services/notification/config/database.php` masih `fnbsense_reporting` — sisa
salin-tempel dari service Reporting. Kalau baris itu hilang, inbox notifikasi
menulis ke database laporan keuangan.
