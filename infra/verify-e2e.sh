#!/usr/bin/env bash
# verify-e2e.sh — bukti rantai lengkap produksi:
#   QR meja → menu → pesan → kasir lihat → konfirmasi bayar → pesanan siap.
#
# Ini versi skrip dari "uji dari HP sungguhan" di docs/DEPLOY.md §8 — satu-satunya
# bukti yang sahih bahwa kelima service tersambung, bukan cuma healthy.
#
# Prasyarat: stack produksi SUDAH nyala + migrasi SUDAH jalan (langkah 4-5 runbook).
# Dipakai untuk dry-run lokal ATAU setelah deploy ke VPS.
#
#   DOMAIN_CUSTOMER / DOMAIN_STAFF  diambil dari .env.production kalau ada
#   BASE  base URL gateway (default https://127.0.0.1 — sertifikat diabaikan)
#   EMAIL / PASSWORD  owner test (default dryrun@fnbsense.test / Password123!)
#
# Keluar non-zero kalau ada satu mata rantai yang gagal.
set -euo pipefail

cd "$(dirname "$0")/.."
ENV_FILE=".env.production"

# --- Konfigurasi -------------------------------------------------------------
BASE="${BASE:-https://127.0.0.1}"
if [ -f "$ENV_FILE" ]; then
  DOMAIN_CUSTOMER="$(grep '^DOMAIN_CUSTOMER=' "$ENV_FILE" | cut -d= -f2-)"
  DOMAIN_STAFF="$(grep '^DOMAIN_STAFF=' "$ENV_FILE" | cut -d= -f2-)"
fi
DOMAIN_CUSTOMER="${DOMAIN_CUSTOMER:?DOMAIN_CUSTOMER wajib (env atau .env.production)}"
DOMAIN_STAFF="${DOMAIN_STAFF:?DOMAIN_STAFF wajib (env atau .env.production)}"
EMAIL="${EMAIL:-dryrun@fnbsense.test}"
PASSWORD="${PASSWORD:-Password123!}"
NAMA_KAFE="Kafe Dry-Run"

PASS=0; FAIL=0
step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }
jqget() { python3 -c "import sys,json; d=json.load(sys.stdin); print(d$1)" 2>/dev/null; }

CUSTOMER=(-H "Host: $DOMAIN_CUSTOMER")
STAFF=(-H "Host: $DOMAIN_STAFF")
# dry-run lokal: domain .test tak punya sertifikat → pakai HTTPS + abaikan cert
[ "${BASE#https}" = "$BASE" ] && TLS=() || TLS=(-k)

# --- 1. Gerbang & service up -------------------------------------------------
step "1. Gerbang: HTTP dialihkan ke HTTPS + /up tiap service"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${CUSTOMER[@]}" "http://127.0.0.1/")
if [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "308" ]; then ok "http → ${HTTP_CODE} (redirect ke https)"; else bad "http ${HTTP_CODE} (harusnya 301/308)"; fi

for path in "iam" "catalog"; do
  CODE=$(curl -s "${TLS[@]}" -o /dev/null -w "%{http_code}" "${STAFF[@]}" "$BASE/$path/up")
  [ "$CODE" = "200" ] && ok "/$path/up → 200" || bad "/$path/up → $CODE"
done

# --- 2. Owner: register atau login -------------------------------------------
step "2. Owner: register (atau login kalau sudah ada)"
REG=$(curl -s "${TLS[@]}" "${STAFF[@]}" -X POST "$BASE/iam/api/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"business_name\":\"$NAMA_KAFE\",\"name\":\"Owner Dry-Run\",\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"password_confirmation\":\"$PASSWORD\"}")
TOKEN=$(echo "$REG" | jqget "['access_token']" 2>/dev/null || true)
if [ -z "$TOKEN" ] || [ "$TOKEN" = "None" ]; then
  LOGIN=$(curl -s "${TLS[@]}" "${STAFF[@]}" -X POST "$BASE/iam/api/auth/login" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")
  TOKEN=$(echo "$LOGIN" | jqget "['access_token']")
  [ -n "$TOKEN" ] && [ "$TOKEN" != "None" ] && ok "login ulang (email sudah terdaftar)" || bad "register & login gagal"
else
  ok "register → token diterima"
fi
AUTH=(-H "Authorization: Bearer $TOKEN")

ME=$(curl -s "${TLS[@]}" "${STAFF[@]}" "${AUTH[@]}" "$BASE/iam/api/auth/me")
# /auth/me mengembalikan user LANGSUNG ({id, role, ...}), bukan {user:{...}}.
ROLE=$(echo "$ME" | jqget "['role']" 2>/dev/null || true)
TENANT=$(echo "$ME" | jqget "['tenant_id']" 2>/dev/null || true)
[ "$ROLE" = "owner" ] && ok "/auth/me → role owner (tenant=$TENANT)" || bad "/auth/me → $ME"

# --- 3. Catalog: kategori + produk -------------------------------------------
step "3. Catalog: buat kategori & produk"
CAT=$(curl -s "${TLS[@]}" "${STAFF[@]}" "${AUTH[@]}" -X POST "$BASE/catalog/api/categories" \
  -H "Content-Type: application/json" -d '{"name":"Kopi"}')
CAT_ID=$(echo "$CAT" | jqget "['data']['id']" 2>/dev/null || true)
[ -n "$CAT_ID" ] && [ "$CAT_ID" != "None" ] && ok "kategori dibuat ($CAT_ID)" || bad "kategori: $CAT"

PROD=$(curl -s "${TLS[@]}" "${STAFF[@]}" "${AUTH[@]}" -X POST "$BASE/catalog/api/products" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Kopi Tubruk\",\"price\":15000,\"category_id\":\"$CAT_ID\"}")
PROD_ID=$(echo "$PROD" | jqget "['data']['id']" 2>/dev/null || true)
[ -n "$PROD_ID" ] && [ "$PROD_ID" != "None" ] && ok "produk dibuat ($PROD_ID)" || bad "produk: $PROD"

# --- 4. Ordering: meja + QR --------------------------------------------------
step "4. Ordering: buat meja, ambil qr_token"
# Label UNIK per run: label meja unik per outlet, jadi run kedua akan 422
# kalau memakai label yang sama. `date` membuat skrip idempoten.
LABEL_MEJA="Meja E2E $(date +%H%M%S)"
TB=$(curl -s "${TLS[@]}" "${STAFF[@]}" "${AUTH[@]}" -X POST "$BASE/ordering/api/tables" \
  -H "Content-Type: application/json" -d "{\"label\":\"$LABEL_MEJA\"}")
QR=$(echo "$TB" | jqget "['data']['qr_token']" 2>/dev/null || true)
[ -n "$QR" ] && [ "$QR" != "None" ] && ok "meja dibuat (qr=$QR)" || bad "meja: $TB"

TB_PUB=$(curl -s "${TLS[@]}" "${CUSTOMER[@]}" "$BASE/ordering/api/t/$QR")
[ -n "$(echo "$TB_PUB" | jqget "['data']['table_id']" 2>/dev/null || true)" ] && ok "pelanggan lihat meja via QR" || bad "t/$QR: $TB_PUB"

# --- 5. Menu publik ----------------------------------------------------------
step "5. Menu pelanggan (Catalog lewat gerbang)"
MENU=$(curl -s "${TLS[@]}" "${CUSTOMER[@]}" "$BASE/catalog/api/menu?tenant=$TENANT")
FOUND=$(echo "$MENU" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(1 if any(p['id']=='$PROD_ID' for c in d.get('data',[]) for p in c.get('products',[])) else 0)" 2>/dev/null || true)
[ "$FOUND" = "1" ] && ok "produk muncul di menu" || bad "menu: $(echo "$MENU" | head -c 200)"

# --- 6. Order: pesan + bayar -------------------------------------------------
step "6. Order: pesan → kasir konfirmasi → siap"
ORD=$(curl -s "${TLS[@]}" "${CUSTOMER[@]}" -X POST "$BASE/ordering/api/orders" \
  -H "Content-Type: application/json" \
  -d "{\"qr_token\":\"$QR\",\"order_type\":\"dine_in\",\"customer_name\":\"Uji E2E\",\"items\":[{\"product_id\":\"$PROD_ID\",\"qty\":1}]}")
ORD_ID=$(echo "$ORD" | jqget "['data']['id']" 2>/dev/null || true)
[ -n "$ORD_ID" ] && [ "$ORD_ID" != "None" ] && ok "order dibuat ($ORD_ID)" || bad "order: $(echo "$ORD" | head -c 300)"

PAID=$(curl -s "${TLS[@]}" "${STAFF[@]}" "${AUTH[@]}" -X POST "$BASE/ordering/api/cashier/orders/$ORD_ID/confirm-payment" \
  -H "Content-Type: application/json" -d '{"payment_method":"cash"}')
STATUS=$(echo "$PAID" | jqget "['data']['status']" 2>/dev/null || true)
[ "$STATUS" = "paid" ] && ok "konfirmasi bayar → status paid" || bad "confirm-payment: $(echo "$PAID" | head -c 300)"

QUEUE=$(curl -s "${TLS[@]}" "${STAFF[@]}" "${AUTH[@]}" "$BASE/ordering/api/cashier/orders")
FOUND=$(echo "$QUEUE" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(1 if any(o['id']=='$ORD_ID' for o in d.get('data',[])) else 0)" 2>/dev/null || true)
[ "$FOUND" = "1" ] && ok "order muncul di antrean kasir" || bad "antrean kasir: $(echo "$QUEUE" | head -c 200)"

# --- Ringkasan ---------------------------------------------------------------
printf '\n\033[1mHASIL: %d PASS, %d FAIL\033[0m\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] && echo "RANTAI LENGKAP TERBUKTI: QR → menu → pesan → bayar → antrean." || echo "ADA MATA RANTAI YANG PUTUS — cek log di atas."
exit "$FAIL"
