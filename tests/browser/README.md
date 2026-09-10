# Tes browser E2E — FNBSense (Selenium)

Suite browser nyata (Selenium + Chrome headless) langsung ke produksi:
kasir.arbitro.dev, pesan.arbitro.dev, owner.arbitro.dev.

## Menjalankan

```bash
cd tests/browser
/home/ubuntu/selenium-venv/bin/python -m pytest                 # semua (termasuk yang menulis data)
/home/ubuntu/selenium-venv/bin/python -m pytest -m "not full"   # cuma baca-baca, tanpa data baru
```

Kredensial via env (default akun owner uji):
`EMAIL_FNBS`, `SANDI_FNBS`.

## Apa yang dites

- `test_customer.py` — menu muat, dialog produk + keranjang, badge Pesanan saya bersih.
- `test_kasir.py` — login + header nama, antrean, shift buka/tutup (selisih 0).
- `test_owner.py` — dashboard jam WIB realtime, laporan (selisih kas shift), daftar karyawan.
- `test_uang.py` (`full`) — alur uang penuh: restok → order → bayar → stok terpotong
  persis resep → sales laporan naik. Diukur lewat API sebelum/sesudah, bukan asumsi.

## Catatan penting

- Test `full` MENULIS data produksi: 1 order kebayar yang tak terhapus + jejak
  shift/stok. Jalankan sadar diri (staging lebih ideal kalau ada).
- Chrome dipakai dari `~/.agent-browser/browsers/chrome-152.0.7977.82` — jangan
  hapus folder itu; Selenium Manager yang memasangkan chromedriver-nya.
- Sistem perlu `kernel.apparmor_restrict_unprivileged_userns=0` (sudah di-set di
  VPS ini) atau Chrome menolak jalan (sandbox).
- Event saga (finance/inventory) bisa telat beberapa detik dari UI → test jajak
  sampai angka mendarat, jangan pakai sleep kaku.
- Klik pakai JS (bukan klik native Selenium) — elemen sticky menutupi target.
- Setiap test di sesi yang sama berbagi kotak localStorage — bersihkan sebelum
  skenario yang butuh keranjang kosong.