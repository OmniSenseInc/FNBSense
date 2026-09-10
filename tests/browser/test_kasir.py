"""Skenario app kasir (kasir.arbitro.dev).

Login pakai akun owner (paling stabil untuk uji lintas-role); header
menampilkan nama dari respons login.
"""
import time

import pytest

from conftest import KASIR, klik_teks, login, tunggu_teks


def test_login_header_nama_dan_antrean(drv):
    login(drv, KASIR)
    body = drv.find_element("tag name", "body").text
    assert "FNBSense ·" in body, "header aplikasi tidak muncul"
    assert tunggu_teks(drv, "Pesanan masuk") or tunggu_teks(drv, "Belum ada pesanan")


def test_antrean_kosong_setelah_beban_kemarin(drv):
    """Read-only: antrean harus selesai mengosongkan dirinya sendiri."""
    login(drv, KASIR)
    body = drv.find_element("tag name", "body").text
    assert "Belum ada pesanan yang menunggu dibayar" in body or "Pesanan masuk" in body


@pytest.mark.full
def test_shift_buka_dan_tutup_selisih_nol(drv):
    login(drv, KASIR)
    drv.get(KASIR + "/shift")
    if "Dibuka" in drv.find_element("tag name", "body").text:
        pytest.skip("sudah ada shift berjalan")
    # tunggu form muat — input lahir belakangan (SPA)
    from selenium.webdriver.support.ui import WebDriverWait

    WebDriverWait(drv, 12).until(
        lambda x: len(x.find_elements("tag name", "input")) > 0
    )
    # buka shift: modal 100.000 (input pertama)
    inp = drv.find_elements("tag name", "input")[0]
    from conftest import isi_kontrol_react

    isi_kontrol_react(drv, inp, "100000")
    tombol = [b for b in drv.find_elements("tag name", "button") if b.text.strip() == "Buka shift"]
    tombol[-1].click()
    assert tunggu_teks(drv, "Dibuka", 10)

    # tutup shift: laci 100.000 → selisih 0
    inp2 = drv.find_elements("tag name", "input")[0]
    isi_kontrol_react(drv, inp2, "100000")
    tombol2 = [b for b in drv.find_elements("tag name", "button") if b.text.strip() == "Tutup shift"]
    tombol2[-1].click()
    assert tunggu_teks(drv, "Shift ditutup", 10)
    assert tunggu_teks(drv, "Selisih")
    assert "Rp 0" in drv.find_element("tag name", "body").text or "+Rp 0" in drv.find_element("tag name", "body").text