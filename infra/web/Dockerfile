# syntax=docker/dockerfile:1
#
# Satu berkas untuk KEDUA app React (apps/customer & apps/staff). Alasannya
# sama seperti infra/php/Dockerfile: yang beda cuma isi foldernya.
#
# Context build-nya folder app, bukan root repo:
#   build: { context: ./apps/staff, dockerfile: ../../infra/web/Dockerfile }

FROM node:22-alpine AS build

WORKDIR /app

# Dependensi dulu, terpisah dari kode — lapisan ini dipakai ulang selama
# package-lock.json tak berubah.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# VITE_* HARUS masuk saat build, bukan saat container menyala: Vite menuliskan
# nilainya langsung ke dalam bundle JavaScript. Container yang sudah jadi tak
# punya lagi tempat membaca variabel ini.
#
# Konsekuensinya: mengubah salah satu nilai di bawah menuntut BUILD ULANG,
# bukan restart. Yang paling mudah lupa adalah VITE_CUSTOMER_URL — ia berakhir
# di dalam QR yang dicetak dan ditempel di meja.
#
# Kedua app memakai berkas ini, jadi daftarnya gabungan; yang tak relevan bagi
# satu app tinggal kosong. Semua nilai di sini PUBLIK — ia terbaca lewat "view
# source" di HP pelanggan. Jangan pernah token atau kunci.
ARG VITE_IAM_URL=/iam
ARG VITE_ORDERING_URL=/ordering
ARG VITE_CATALOG_URL=/catalog
ARG VITE_NOTIFICATION_URL=/notification
ARG VITE_INVENTORY_URL=/inventory
ARG VITE_CUSTOMER_URL=
ARG VITE_NAMA_KAFE=Kafe

ENV VITE_IAM_URL=$VITE_IAM_URL \
    VITE_ORDERING_URL=$VITE_ORDERING_URL \
    VITE_CATALOG_URL=$VITE_CATALOG_URL \
    VITE_NOTIFICATION_URL=$VITE_NOTIFICATION_URL \
    VITE_INVENTORY_URL=$VITE_INVENTORY_URL \
    VITE_CUSTOMER_URL=$VITE_CUSTOMER_URL \
    VITE_NAMA_KAFE=$VITE_NAMA_KAFE

RUN npm run build

# Node tak ikut ke image akhir: yang dilayani cuma berkas statis, dan runtime
# JavaScript di sana hanya menambah permukaan serang tanpa dipakai.
FROM nginx:1-alpine

COPY --from=build /app/dist /usr/share/nginx/html

# Konfignya ditulis di sini, bukan disalin dari infra/web/: sumber COPY wajib
# berada di dalam build context, dan context-nya folder app — berkas di
# infra/web/ tak terjangkau dari sana.
#
# try_files ... /index.html adalah SATU-SATUNYA baris yang benar-benar penting.
# Rute app ini di sisi klien: /dapur, /nota/<id>, /t/<token> tak punya berkas
# padanan di disk. Tanpa fallback, semuanya jalan saat diklik dari dalam app
# dan 404 begitu di-refresh atau dibuka langsung dari QR.
RUN cat > /etc/nginx/conf.d/default.conf <<'EOF'
server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;

    # Berkas ber-hash dari Vite: namanya berubah tiap build, jadi aman
    # di-cache selamanya. index.html TIDAK — ia yang menunjuk ke nama-nama
    # itu, dan versi lamanya akan menunjuk ke berkas yang sudah tak ada.
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files $uri $uri/ /index.html;
    }

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;
}
EOF

EXPOSE 80

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q --spider http://127.0.0.1/ || exit 1
