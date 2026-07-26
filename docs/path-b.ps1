# Path B — bukti rantai HARFIAH: order -> confirm-payment -> outbox -> relay -> broker -> consumer.
#
# Beda dari F4d/F5b: di sana amplop order.paid DISUNTIK langsung ke exchange.
# Di sini tak ada jalan pintas — semua lewat API produksi.
#
# Consumer pembukti: FINANCE (bukan Inventory). Finance tak memanggil Catalog dan
# tak butuh resep/saldo stok, jadi seluruh seed bahan + mode gagalnya hilang.
# Yang dibuktikan Path B memang rantainya, bukan logika potong stok (sudah F4d).
#
# Prasyarat: MySQL + RabbitMQ hidup, dan 4 terminal jalan:
#   IAM :8002 | Catalog :8001 | Ordering :8000 | services/finance -> php artisan finance:consume

$ErrorActionPreference = 'Stop'
$IAM = 'http://localhost:8002/api'
$CAT = 'http://localhost:8001/api'
$ORD = 'http://localhost:8000/api'
$rnd = Get-Random -Maximum 999999

function Kirim($metode, $url, $body, $token) {
    $h = @{}
    if ($token) { $h['Authorization'] = "Bearer $token" }
    $json = if ($null -ne $body) { $body | ConvertTo-Json -Depth 8 } else { $null }
    return Invoke-RestMethod -Method $metode -Uri $url -Headers $h -ContentType 'application/json' -Body $json
}
function Tahap($n, $teks) { Write-Host "`n[$n] $teks" -ForegroundColor Cyan }

Tahap 1 'IAM: daftar owner (bikin tenant + outlet default sekaligus)'
$owner = Kirim Post "$IAM/auth/register" @{
    business_name = "Kopi Senja $rnd"; name = 'Vincent'
    email = "owner$rnd@kopisenja.test"
    password = 'Password123'; password_confirmation = 'Password123'
}
$OT = $owner.access_token; $TENANT = $owner.user.tenant_id; $OUTLET = $owner.user.outlet_id
Write-Host "    tenant=$TENANT outlet=$OUTLET"

Tahap 2 'IAM: owner membuat kasir (F-iam-b) lalu kasir login'
$kasirEmail = "kasir$rnd@kopisenja.test"
Kirim Post "$IAM/staff" @{ name = 'Kasir Satu'; email = $kasirEmail; password = 'Kasir12345' } $OT | Out-Null
$kasir = Kirim Post "$IAM/auth/login" @{ email = $kasirEmail; password = 'Kasir12345' }
$KT = $kasir.access_token
Write-Host "    role=$($kasir.user.role) outlet=$($kasir.user.outlet_id)"
if ($kasir.user.role -ne 'cashier') { throw 'role kasir tidak cashier' }
if (-not $kasir.user.outlet_id) { throw 'token kasir tanpa outlet_id -> confirm-payment pasti 404' }

Tahap 3 'Catalog: kategori + produk'
$kat = Kirim Post "$CAT/categories" @{ name = 'Kopi' } $OT
$katId = $kat.id; if (-not $katId) { $katId = $kat.data.id }
$prod = Kirim Post "$CAT/products" @{ name = 'Kopi Susu'; category_id = $katId; price = 25000 } $OT
$prodId = $prod.id; if (-not $prodId) { $prodId = $prod.data.id }
Write-Host "    product=$prodId harga=25000"

Tahap 4 'Ordering: buat meja + ambil qr_token'
$meja = Kirim Post "$ORD/tables" @{ label = "M$rnd" } $OT
$qr = $meja.qr_token; if (-not $qr) { $qr = $meja.data.qr_token }
if (-not $qr) { $meja | ConvertTo-Json -Depth 8 | Write-Host; throw 'qr_token tak ditemukan di respons meja' }
Write-Host "    qr_token=$qr"

Tahap 5 'Customer (PUBLIK, tanpa login): buat order 2x Kopi Susu'
$order = Kirim Post "$ORD/orders" @{
    qr_token = $qr; order_type = 'dine_in'; customer_name = 'Tamu Uji'
    items = @(@{ product_id = $prodId; qty = 2 })
}
$orderId = $order.id; if (-not $orderId) { $orderId = $order.data.id }
$grand = $order.grand_total; if (-not $grand) { $grand = $order.data.grand_total }
Write-Host "    order=$orderId grand_total=$grand"

Tahap 6 'Kasir: confirm-payment -> PAID + 1 baris outbox'
Kirim Post "$ORD/cashier/orders/$orderId/confirm-payment" @{ payment_method = 'cash' } $KT | Out-Null
Write-Host '    PAID'

Tahap 7 'Ordering: outbox:relay --once -> publish ke RabbitMQ'
Push-Location (Join-Path $PSScriptRoot '..\services\ordering')
php artisan outbox:relay --once
Pop-Location

Tahap 8 'Verifikasi di DB Finance (consumer harus sudah mencatatnya)'
$mysql = Get-ChildItem 'C:\laragon\bin\mysql' -Directory | ForEach-Object { Join-Path $_.FullName 'bin\mysql.exe' } | Where-Object { Test-Path $_ } | Select-Object -First 1
$sql = "SELECT order_id, grand_total, payment_method FROM sales WHERE order_id='$orderId';"
$hasil = & $mysql -u root fnbsense_finance -e $sql
$hasil

if ("$hasil" -match [regex]::Escape($orderId)) {
    Write-Host "`nPATH B LULUS - rantai harfiah tersambung tanpa suntikan manual." -ForegroundColor Green
} else {
    Write-Host "`nBELUM tercatat di Finance." -ForegroundColor Yellow
    Write-Host 'Cek: terminal finance:consume hidup? RabbitMQ UI -> queue finance.sales punya Ready?'
    Write-Host "order_id = $orderId"
}