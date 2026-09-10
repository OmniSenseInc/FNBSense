#!/usr/bin/env bash
# install-cron.sh — pasang backup harian 03:00 WIB (Asia/Jakarta) via cron.
# Jalankan sebagai root:  sudo ./install-cron.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CRON_FILE="/etc/cron.d/fnbsense-backup"

# TZ Asia/Jakarta (VPS di Indonesia). Kalau mau jam lain, ubah baris berikut.
CRON_LINE="0 3 * * * root BACKUP_DIR=/var/backups/fnbsense KEEP_DAYS=7 ${SCRIPT_DIR}/backup-db.sh >> /var/backups/fnbsense/backup.log 2>&1"

mkdir -p /var/backups/fnbsense
umask 077

echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" > "$CRON_FILE"
echo "SHELL=/bin/bash" >> "$CRON_FILE"
echo "$CRON_LINE" >> "$CRON_FILE"
chmod 644 "$CRON_FILE"

# Verifikasi — cari penanda yang BENERAN ada di isi file (nama skrip), bukan
# nama file cron-nya.
if grep -q 'backup-db.sh' "$CRON_FILE"; then
  echo "OK: cron terpasang di $CRON_FILE"
  cat "$CRON_FILE"
else
  echo "GAGAL: cron tidak terpasang" >&2
  exit 1
fi
