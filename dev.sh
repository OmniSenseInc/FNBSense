#!/usr/bin/env bash
# FNBSense dev launcher (Linux) — padanan dev.ps1
# Menyalakan infra Docker + semua service Laravel + frontend dev.
# Port ditulis eksplisit supaya urutan penyalaan tak pernah menukar service.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

PORTS=(8000 8001 8002 8003 8004 8005 8006 5173 5174)
BENTROK=0
for p in "${PORTS[@]}"; do
  if ss -tln 2>/dev/null | grep -q ":$p "; then
    PID=$(ss -tlnp 2>/dev/null | grep ":$p " | grep -oP 'pid=\K[0-9]+' | head -1)
    echo "WARN: port $p sudah dipakai (PID $PID)"
    BENTROK=1
  fi
done
[ $BENTROK -eq 1 ] && echo "Hentikan pemakai port dulu." && exit 1

# Infra (Traefik, RabbitMQ, Redis)
sudo docker compose up -d

start() { # name, dir, cmd
  echo "menyalakan $1"
  (cd "$ROOT/$2" && nohup $3 >/tmp/fnbsense-$1.log 2>&1 &)
}

start iam       services/iam        "php artisan serve --host=127.0.0.1 --port=8002"
start catalog   services/catalog    "php artisan serve --host=127.0.0.1 --port=8001"
start ordering  services/ordering   "php artisan serve --host=127.0.0.1 --port=8000"
start inventory services/inventory  "php artisan serve --host=127.0.0.1 --port=8003"
start finance   services/finance    "php artisan serve --host=127.0.0.1 --port=8004"
start reporting services/reporting  "php artisan serve --host=127.0.0.1 --port=8005"
start notification services/notification "php artisan serve --host=127.0.0.1 --port=8006"
start customer  apps/customer       "npm run dev -- --port 5173"
start staff     apps/staff          "npm run dev -- --port 5174"
# scheduler orders:expire (padanan jadwal di dev.ps1)
start jadwal    services/ordering   "php artisan schedule:work"

# Event pipeline (RabbitMQ): outbox relay + consumers
start relay     services/ordering   "php artisan outbox:relay"
start invcons   services/inventory  "php artisan inventory:consume"
start fincons   services/finance    "php artisan finance:consume"
start repcons   services/reporting  "php artisan reporting:consume"
start noticons  services/notification "php artisan notification:consume"

echo ""
echo "Kasir   : http://localhost:5174"
echo "IAM     : http://127.0.0.1:8002  (login: owner@fnbsense.test / Password123!)"
echo "Log     : /tmp/fnbsense-<nama>.log"
