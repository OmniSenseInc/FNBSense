# Penyala lingkungan pengembangan FNBSense.
#
# ADA SEBABNYA skrip ini ada. `php artisan serve` tanpa --port selalu mencoba
# 8000 lebih dulu, dan kalau port itu sudah terpakai Laravel DIAM-DIAM naik ke
# 8001. Jadi menyalakan service dengan urutan berbeda saja sudah cukup untuk
# menukar Ordering dan Catalog — tanpa satu pun pesan error. Semuanya terlihat
# menyala normal, tapi app pelanggan menanyakan menu ke service yang tak punya
# menu, lalu cuma menerima 404 yang tak menjelaskan apa-apa.
#
# Itu sudah memakan waktu dua hari. Port di sini ditulis eksplisit supaya
# urutan penyalaan tak pernah lagi menentukan siapa mendapat port apa.
#
# Pakai:  .\dev.ps1

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

$layanan = @(
    @{ Nama = 'ordering'; Jalur = 'services\ordering'; Port = 8000; Perintah = 'php artisan serve --port=8000' }
    @{ Nama = 'catalog';  Jalur = 'services\catalog';  Port = 8001; Perintah = 'php artisan serve --port=8001' }
    @{ Nama = 'iam';      Jalur = 'services\iam';      Port = 8002; Perintah = 'php artisan serve --port=8002' }
    @{ Nama = 'customer'; Jalur = 'apps\customer';     Port = 5173; Perintah = 'npm run dev' }
    @{ Nama = 'staff';    Jalur = 'apps\staff';        Port = 5174; Perintah = 'npm run dev' }
)

# Port diperiksa SEMUA dulu, sebelum menyalakan apa pun. Kalau tidak, sebagian
# layanan terlanjur hidup di port yang salah dan kita kembali ke persoalan yang
# sama — separuh benar justru lebih menyesatkan daripada mati total.
$bentrok = @()
foreach ($l in $layanan) {
    $dipakai = Get-NetTCPConnection -State Listen -LocalPort $l.Port -ErrorAction SilentlyContinue
    if ($dipakai) {
        $prosesId = ($dipakai | Select-Object -First 1).OwningProcess
        $bentrok += "  port $($l.Port) ($($l.Nama)) sudah dipakai PID $prosesId"
    }
}

if ($bentrok.Count -gt 0) {
    Write-Host "Port berikut sudah terpakai:" -ForegroundColor Yellow
    $bentrok | ForEach-Object { Write-Host $_ -ForegroundColor Yellow }
    Write-Host ""
    Write-Host "Matikan dulu, lalu jalankan ulang skrip ini:" -ForegroundColor Yellow
    Write-Host '  Get-NetTCPConnection -State Listen -LocalPort 8000,8001,8002,5173,5174 |'
    Write-Host '    Select-Object -ExpandProperty OwningProcess -Unique |'
    Write-Host '    ForEach-Object { Stop-Process -Id $_ -Force }'
    exit 1
}

foreach ($l in $layanan) {
    $kerja = Join-Path $root $l.Jalur
    Write-Host "menyalakan $($l.Nama) di port $($l.Port)"
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "Set-Location '$kerja'; $($l.Perintah)"
}

Write-Host ""
Write-Host "Pelanggan : http://localhost:5173/t/<qr_token>"
Write-Host "Kasir     : http://localhost:5174"
Write-Host ""
Write-Host "Kalau menu tetap tak muncul, pastikan port memang benar:" -ForegroundColor Cyan
Write-Host '  Get-CimInstance Win32_Process -Filter "Name=''php.exe''" | Select-Object CommandLine'
