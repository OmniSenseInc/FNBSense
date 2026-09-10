# syntax=docker/dockerfile:1
#
# Satu berkas untuk KELIMA service Laravel. Yang membedakan mereka cuma isi
# folder — bukan cara membangunnya — jadi lima Dockerfile identik hanya akan
# jadi lima tempat yang harus diingat saat versi PHP naik.
#
# Context build-nya adalah folder service (mis. services/iam), bukan root repo:
#   build: { context: ./services/iam, dockerfile: ../../infra/php/Dockerfile }
#
# FrankenPHP, bukan nginx+php-fpm. Satu proses, satu container, satu tempat
# melihat log. Dua container per service berarti sepuluh container hanya untuk
# menyajikan lima aplikasi, dan setiap kegagalan harus ditelusuri di dua tempat.

# php8.5, bukan 8.3 seperti yang tertulis di composer.json ("php": "^8.3").
#
# Yang mengikat bukan composer.json melainkan composer.lock, dan lock itu
# diselesaikan di mesin pengembangan ber-PHP 8.5 — ia menarik symfony 8.1.x yang
# menuntut >=8.4.1. Build dengan 8.3 gagal di `composer install` dengan belasan
# blok "Problem" yang tak satu pun menyebut versi image sebagai sebabnya.
#
# 8.5 dan bukan 8.4 walau 8.4 sudah cukup memenuhi lock: 8.5 adalah versi yang
# benar-benar dipakai menjalankan seluruh test service ini. Produksi di 8.4
# berarti menjalankan versi yang tak pernah sekali pun diuji.
FROM dunglas/frankenphp:1-php8.5-alpine

# Daftarnya sependek ini dengan sengaja. Tiap ekstensi di sini menarik paket
# build lewat jaringan saat image dibangun, dan jaringan itulah bagian build
# yang paling sering gagal.
#
# pdo_mysql: semua service. sockets+bcmath: php-amqplib (relay & consumer).
# pcntl: daemon butuh sinyal supaya `docker stop` berhenti rapi, bukan dibunuh.
# gd: re-encode gambar QRIS menjadi PNG (SettingController::reencode).
#
# TIDAK dipasang: opcache (sudah ada di image dasar — memintanya cuma
# menghasilkan peringatan), intl dan zip (tak satu pun kode di kelima service
# menyentuhnya; keduanya menarik icu-dev dan libzip-dev yang berat).
RUN install-php-extensions pdo_mysql bcmath sockets pcntl gd

# php.ini bawaan image adalah "development": display_errors menyala dan jejak
# stack ikut terkirim ke browser. Di jalur uang itu bocornya nama tabel.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Dependensi lebih dulu, terpisah dari kode: selama composer.lock tak berubah,
# lapisan ini dipakai ulang dan build ulang cuma menyalin kode.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# `storage:link` di bawah membuat public/storage -> storage/app/public.
#
# Tanpa itu gambar QRIS yang diunggah owner tersimpan dengan benar tapi TIDAK
# PERNAH bisa diambil: alamat yang dikirim ke pelanggan (/storage/qris/....png)
# menunjuk ke jalur yang tak ada di public/, dan yang terlihat di HP pelanggan
# cuma gambar rusak di halaman bayar. Kegagalannya sunyi — tak ada log, tak ada
# galat, cuma tak ada yang bisa membayar.
#
# Dijalankan untuk kelima service walau hanya Ordering yang menyimpan berkas:
# perintahnya idempoten dan tak berbiaya, sedangkan mengecualikan empat service
# menuntut Dockerfile yang tahu ia sedang membangun yang mana.
#
# .env DIHAPUS, bukan sekadar diabaikan lewat .dockerignore. Keduanya dipasang
# karena yang satu bisa lupa: image yang terlanjur membawa .env pengembangan
# akan diam-diam memakai kredensial itu di produksi kalau satu variabel env
# saja luput di-set — dan tak ada yang memberi tahu.
#
# bootstrap/cache/*.php ikut dihapus untuk alasan yang sama dengan
# .dockerignore: packages.php/services.php hasil generate di mesin
# pengembangan memuat provider DARI dependensi dev (Collision, Pail, ...) yang
# tidak ada di image --no-dev. Kalau satu service lupa .dockerignore-nya,
# build mati dengan "Class ... not found" yang tak menyebut berkas ini sama
# sekali (terbukti: finance, 2026-08-12). Menghapusnya di sini membuat
# Dockerfile ini kebal terhadap cache basi apa pun yang ikut tersalin.
RUN rm -f .env .env.* bootstrap/cache/*.php \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && php artisan storage:link --force \
    && chown -R www-data:www-data storage bootstrap/cache

# /data dan /config milik Caddy, mesin di dalam FrankenPHP. Ia menulis ke sana
# saat menyala, dan sebagai www-data ia tak boleh — container mati sebelum
# permintaan pertama, dengan pesan soal berkas Caddy yang tak menyebut Laravel
# sama sekali. Baris ini yang membuat "turun dari root" di bawah bisa dilakukan.
RUN chown -R www-data:www-data /data /config

# :8080, bukan :80 — port di atas 1024 tak menuntut root, jadi proses ini bisa
# turun ke www-data. Traefik yang menghadap internet; container ini tak perlu
# hak yang lebih besar dari yang dipakainya.
ENV SERVER_NAME=:8080
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD wget -q --spider http://127.0.0.1:8080/up || exit 1
