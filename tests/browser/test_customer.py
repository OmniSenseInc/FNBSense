"""Skenario layar customer (pesan.arbitro.dev).

Read-only kecuali ditandai `full` (yang mengirim pesanan beneran).
"""
import re
import time

from conftest import BASE, QR_MEJA3, klik_teks, tunggu_teks

URL_MEJA = f"{BASE}/t/{QR_MEJA3}"


def test_menu_muat_dengan_produk(drv):
    drv.get(URL_MEJA)
    assert tunggu_teks(drv, "meja 3")
    assert tunggu_teks(drv, "Kopi Tubruk")
    assert "Rp 15.000" in drv.find_element("tag name", "body").text


def test_dialog_produk_dan_keranjang(drv):
    drv.get(URL_MEJA)
    assert tunggu_teks(drv, "es teh anget")
    # buka dialog detail produk
    kartu = drv.find_elements(
        "xpath", ".//button[contains(normalize-space(.), 'es teh anget')]"
    )[0]
    kartu.click()
    assert tunggu_teks(drv, "Tambah ke keranjang")
    klik_teks(drv, "Tambah ke keranjang")
    # bar keranjang muncul
    assert tunggu_teks(drv, "Pesan sekarang")


def test_pesanan_saya_tidak_ada_badge_bila_belum_order(drv):
    """Browser uji bersih (localStorage kosong) → tombol Pesanan saya tak
    tampil. Artinya tidak ada riwayat pesanan basi di HP ini."""
    drv.get(URL_MEJA)
    assert tunggu_teks(drv, "meja 3")
    import time

    time.sleep(2)
    badge = drv.find_elements(
        "xpath", ".//button[contains(normalize-space(.), 'Pesanan saya')]"
    )
    assert not badge, "badge Pesanan saya tidak seharusnya muncul dengan riwayat kosong"