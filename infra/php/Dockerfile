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

FROM dunglas/frankenphp:1-php8.3-alpine

# Daftarnya sependek ini dengan sengaja. Tiap ekstensi di sini menarik paket
# build lewat jaringan saat image dibangun, dan jaringan itulah bagian build
# yang paling sering gagal.
#
# pdo_mysql: semua service. sockets+bcmath: php-amqplib (relay & consumer).
# pcntl: daemon butuh sinyal supaya `docker stop` berhenti rapi, bukan dibunuh.
#
# TIDAK dipasang: opcache (sudah ada di image dasar — memintanya cuma
# menghasilkan peringatan), intl dan zip (tak satu pun kode di kelima service
# menyentuhnya; keduanya menarik icu-dev dan libzip-dev yang berat).
RUN install-php-extensions pdo_mysql bcmath sockets pcntl

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

# .env DIHAPUS, bukan sekadar diabaikan lewat .dockerignore. Keduanya dipasang
# karena yang satu bisa lupa: image yang terlanjur membawa .env pengembangan
# akan diam-diam memakai kredensial itu di produksi kalau satu variabel env
# saja luput di-set — dan tak ada yang memberi tahu.
RUN rm -f .env .env.* \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
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
