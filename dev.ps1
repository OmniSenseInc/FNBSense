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
    # Tanpa ini `orders:expire` TIDAK PERNAH jalan di pengembangan, dan itu
    # menyesatkan dengan cara yang mahal: pesanan menumpuk sebagai PENDING
    # selamanya, hitung mundur di kartu kasir menghitung ke tenggat yang tak
    # pernah tiba, dan status `expired` mustahil diuji lewat layar. Produksi
    # memakai cron; di sini `schedule:work` yang menirunya.
    #
    # Port $null: ia tak mendengarkan apa pun, jadi tak ikut pemeriksaan bentrok.
    @{ Nama = 'jadwal';   Jalur = 'services\ordering'; Port = $null; Perintah = 'php artisan schedule:work' }
)

# Port diperiksa SEMUA dulu, sebelum menyalakan apa pun. Kalau tidak, sebagian
# layanan terlanjur hidup di port yang salah dan kita kembali ke persoalan yang
# sama — separuh benar justru lebih menyesatkan daripada mati total.
$bentrok = @()
foreach ($l in $layanan) {
    if ($null -eq $l.Port) { continue }
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
    $di = if ($null -eq $l.Port) { '' } else { " di port $($l.Port)" }
    Write-Host "menyalakan $($l.Nama)$di"
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "Set-Location '$kerja'; $($l.Perintah)"
}

Write-Host ""
Write-Host "Kasir : http://localhost:5174"
Write-Host ""
# Alamat pelanggan TIDAK dicetak sebagai contoh berpola. Versi sebelumnya
# menulis '/t/<qr_token>', dan bentuknya terlalu mirip alamat siap salin —
# begitu disalin apa adanya, server membalas 404 dan app pelanggan berkata
# "scan QR di meja", yang menuntun ke dugaan yang sama sekali keliru.
Write-Host "Alamat pelanggan (token asli tiap meja):" -ForegroundColor Cyan
Write-Host "  cd services\ordering; php artisan meja:daftar"
Write-Host ""
Write-Host "Kalau menu tetap tak muncul, pastikan port memang benar:" -ForegroundColor Cyan
Write-Host '  Get-CimInstance Win32_Process -Filter "Name=''php.exe''" | Select-Object CommandLine'
