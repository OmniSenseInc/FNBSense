#!/usr/bin/env bash
# backup-db.sh — backup SEMUA database FNBSense, rotasi, opsi offsite.
#
# PENTING: tanpa backup, VPS mati = data penjualan & keuangan hilang permanen.
# Pasang cron (lihat install-cron.sh) supaya jalan tiap hari.
#
# Cara kerja:
#   1. Deteksi target: container produksi fnbsense-mysql kalau ada,
#      kalau tidak MySQL lokal (dev).
#   2. Dump semua database fnbsense_* (single-transaction, konsisten).
#   3. Simpan ke $BACKUP_DIR/<timestamp>/ — gzip per database + manifest.
#   4. Hapus backup lebih tua dari $KEEP_DAYS.
#   5. Kalau rclone remote "fnbsense-backup" terkonfigurasi, salin ke sana.
#
# Variabel (semua opsional):
#   BACKUP_DIR  default /var/backups/fnbsense
#   KEEP_DAYS   default 7
#   MYSQL_PASSWORD  kalau kosong: coba fnbsense_dev (lokal) lalu file .env.production
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/fnbsense}"
KEEP_DAYS="${KEEP_DAYS:-7}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DEST="$BACKUP_DIR/$STAMP"
LOG="${BACKUP_DIR}/backup.log"

mkdir -p "$DEST"

log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG"; }

# --- Cari sandi --------------------------------------------------------------
# Target container = produksi: sandi dari .env.production.
# Target lokal (dev): coba fnbsense_dev dulu, lalu sandi dari .env.production.
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'fnbsense-mysql'; then
  ENV_FILE="/home/ubuntu/fnbsense/.env.production"
  [ -f "$ENV_FILE" ] && MYSQL_PASSWORD="$(grep '^MYSQL_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
else
  MYSQL_PASSWORD="${MYSQL_PASSWORD:-fnbsense_dev}"
  if ! mysqladmin ping -ufnbsense -p"$MYSQL_PASSWORD" --silent 2>/dev/null; then
    ENV_FILE="/home/ubuntu/fnbsense/.env.production"
    [ -f "$ENV_FILE" ] && MYSQL_PASSWORD="$(grep '^MYSQL_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
  fi
fi

# --- Deteksi target ----------------------------------------------------------
if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'fnbsense-mysql'; then
  log "Target: container fnbsense-mysql"
  TARGET="container fnbsense-mysql"
  exec_mysql()  { docker exec fnbsense-mysql mysql  -ufnbsense -p"$MYSQL_PASSWORD" -N -e "$1"; }
  exec_dump()   { docker exec fnbsense-mysql mysqldump -ufnbsense -p"$MYSQL_PASSWORD" \
                    --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$1"; }
else
  log "Target: MySQL lokal"
  TARGET="MySQL lokal"
  exec_mysql()  { mysql  -ufnbsense -p"$MYSQL_PASSWORD" -N -e "$1"; }
  exec_dump()   { mysqldump -ufnbsense -p"$MYSQL_PASSWORD" \
                    --single-transaction --routines --triggers --no-tablespaces "$1"; }
fi

# --- Dump --------------------------------------------------------------------
DBS="$(exec_mysql "SHOW DATABASES LIKE 'fnbsense_%';")" || {
  log "GAGAL: tidak bisa menanyakan daftar database (sandi salah?)."
  exit 1
}

if [ -z "$DBS" ]; then
  log "GAGAL: tidak ada database fnbsense_* ditemukan."
  exit 1
fi

COUNT=0
for db in $DBS; do
  if exec_dump "$db" | gzip > "$DEST/$db.sql.gz"; then
    COUNT=$((COUNT+1))
  else
    log "GAGAL dump $db"
  fi
done

# manifest — berguna untuk verifikasi isi backup tanpa membuka tiap file
{
  echo "waktu: $(date --iso-8601=seconds)"
  echo "target: ${TARGET:-auto}"
  echo "database:"
  for db in $DBS; do
    echo "  $db ($(du -h "$DEST/$db.sql.gz" | cut -f1))"
  done
} > "$DEST/MANIFEST.txt"

log "Selesai: $COUNT database -> $DEST"

# --- Rotasi ------------------------------------------------------------------
OLD="$(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" 2>/dev/null)"
if [ -n "$OLD" ]; then
  rm -rf $OLD
  log "Rotasi: hapus $(echo "$OLD" | wc -l) backup lebih tua dari ${KEEP_DAYS} hari"
fi

# --- Offsite (opsional) ------------------------------------------------------
if command -v rclone >/dev/null 2>&1 && rclone listremotes 2>/dev/null | grep -q '^fnbsense-backup:'; then
  rclone copy "$DEST" fnbsense-backup:fnbsense/$(date +%Y%m%d) --log-file "$LOG" --log-level ERROR
  log "Offsite: tersalin ke rclone fnbsense-backup"
fi

# --- Ringkas (cron no_agent friendly: diam kalau sukses) ---------------------
if [ "$COUNT" -gt 0 ]; then
  echo "OK: $COUNT database -> $DEST"
fi
