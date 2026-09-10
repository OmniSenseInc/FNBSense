"""Skenario dashboard owner (owner.arbitro.dev) — read-only."""
from conftest import OWNER, klik_teks, login, tunggu_teks


def test_login_dashboard_jam_wib(drv):
    login(drv, OWNER)
    assert tunggu_teks(drv, "FNBSense · Owner")
    body = drv.find_element("tag name", "body").text
    # label jam WIB realtime (HH.MM WIB) — bukan tulisan WIB statis semata
    import re

    assert re.search(r"\d{2}\.\d{2} WIB", body), "jam WIB realtime tidak ada"


def test_laporan_muat_selisih_shift(drv):
    login(drv, OWNER)
    drv.get(OWNER + "/laporan")
    assert tunggu_teks(drv, "Laporan laba rugi")
    assert tunggu_teks(drv, "Selisih kas (shift)")


def test_karyawan_daftar_muat(drv):
    login(drv, OWNER)
    drv.get(OWNER + "/staf")
    assert tunggu_teks(drv, "Karyawan")
    assert tunggu_teks(drv, "Kasir baru")