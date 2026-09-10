"""Alur uang penuh (full): restok → order → bayar → stok kepotong → keuangan.

Membuktikan rantai order→paid→inventory→finance hidup di produksi, diukur
lewat angka API sebelum dan sesudah — bukan asumsi.

MENULIS DATA: 1 order kebayar; stok balik net-zero (restok susu +150 lalu
order 1 porsi yang memotongnya −150).
"""
import datetime
import json
import re
import time
import urllib.request

import pytest

from conftest import (
    BASE,
    KASIR,
    OWNER,
    QR_MEJA3,
    EMAIL,
    SANDI,
    RESEP_KOPI,
    isi_kontrol_react,
    klik_teks,
    login,
    tunggu_teks,
)

URL_MEJA = f"{BASE}/t/{QR_MEJA3}"


def api(method, url, token=None, data=None):
    req = urllib.request.Request(url, method=method)
    req.add_header("Accept", "application/json")
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    if data is not None:
        req.add_header("Content-Type", "application/json")
        req.data = json.dumps(data).encode()
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.status, json.loads(r.read() or b"{}")


def token_owner():
    st, b = api("POST", "https://kasir.arbitro.dev/iam/api/auth/login",
                data={"email": EMAIL, "password": SANDI})
    assert st == 200
    return b["access_token"]


def laporan_7h(tok):
    hari = datetime.date.today()
    dari = (hari - datetime.timedelta(days=7)).isoformat()
    st, b = api("GET", f"{OWNER}/finance/api/reports?from={dari}&to={hari.isoformat()}", tok)
    assert st == 200
    return b.get("data", b)


def stok(tok):
    st, b = api("GET", f"{OWNER}/inventory/api/stock", tok)
    assert st == 200
    return {x["ingredient_name"]: float(x["qty_on_hand"]) for x in b["data"]}


@pytest.mark.full
def test_uang_order_bayar_stok_dan_laporan(drv):
    tok = token_owner()
    seb_sales = laporan_7h(tok)["sales"]
    seb_stok = stok(tok)

    # 1) Restok susu +150 agar Kopi Susu bisa dipesan (stok < 1 porsi).
    #    Net-zero: order di langkah 3 memotongnya kembali.
    login(drv, KASIR)
    drv.get(KASIR + "/stok")
    assert tunggu_teks(drv, "Barang masuk")
    leaf = drv.find_element(
        "xpath", "//*[contains(normalize-space(.), 'Susu') and not(descendant::*)]"
    )
    kart = leaf
    for _ in range(6):
        kart = kart.find_element("xpath", "..")
        if "Barang masuk" in kart.text:
            break
    tombol = [b for b in kart.find_elements("tag name", "button") if b.text.strip() == "Barang masuk"]
    drv.execute_script("arguments[0].click();", tombol[-1])
    inp = drv.find_elements("tag name", "input")[-1]
    isi_kontrol_react(drv, inp, "150")
    simpan = [b for b in drv.find_elements("tag name", "button") if b.text.strip() == "Simpan"]
    drv.execute_script("arguments[0].click();", simpan[-1])
    time.sleep(2)
    assert stok(tok)["Susu"] == pytest.approx(seb_stok["Susu"] + 150, abs=0.001)

    # 2) Customer: order Kopi Susu ×1
    drv.get(URL_MEJA)
    # Keranjang lokal dibersihkan — test customer sebelumnya meninggalkan isi.
    drv.execute_script("localStorage.clear()")
    drv.get(URL_MEJA)
    assert tunggu_teks(drv, "Kopi Susu")
    kartu_produk = drv.find_elements("xpath", ".//button[contains(normalize-space(.), 'Kopi Susu')]")[0]
    drv.execute_script("arguments[0].click();", kartu_produk)
    assert tunggu_teks(drv, "Tambah ke keranjang")
    klik_teks(drv, "Tambah ke keranjang")
    assert tunggu_teks(drv, "Pesan sekarang")
    drv.execute_script(
        "arguments[0].click();",
        drv.find_element("xpath", "//a[contains(normalize-space(.), 'Pesan sekarang')]"),
    )
    assert tunggu_teks(drv, "Nama pemesan")
    isi_kontrol_react(drv, drv.find_elements("tag name", "input")[0], "Uji Selenium")
    drv.execute_script(
        "arguments[0].click();",
        drv.find_element("xpath", "//label[contains(normalize-space(.), 'Tunai')]"),
    )
    kirim = [b for b in drv.find_elements("tag name", "button") if "Kirim pesanan" in (b.text or "")]
    assert kirim and kirim[0].is_enabled()
    drv.execute_script("arguments[0].click();", kirim[0])
    assert tunggu_teks(drv, "Nomor pesanan")
    nomor = re.search(r"[A-Z0-9]{6}", drv.find_element("tag name", "body").text)
    assert nomor, "nomor order tidak ditemukan"
    nomor = nomor.group(0)

    # 3) Kasir: konfirmasi bayar Tunai
    login(drv, KASIR)
    assert tunggu_teks(drv, nomor)
    klik_teks(drv, "Konfirmasi bayar")
    assert tunggu_teks(drv, "Terima Tunai")
    klik_teks(drv, "Terima Tunai")
    assert tunggu_teks(drv, "Pembayaran tercatat")

    # 4) Verifikasi angka (1 porsi Kopi Susu ≈ 25.000 + layanan + pajak).
    #    Event saga (ordering→finance/inventory) bisa tertinggal beberapa detik
    #    dari konfirmasi UI — jajak sampai angkanya mendarat.
    selisih = 0
    for _ in range(10):
        selisih = laporan_7h(tok)["sales"]["total"] - seb_sales["total"]
        if 30100 <= selisih <= 31200:
            break
        time.sleep(3)
    assert 30100 <= selisih <= 31200, f"selisih sales {selisih} di luar rentang"
    stk = {}
    for _ in range(10):
        stk = stok(tok)
        if stk["Susu"] == pytest.approx(seb_stok["Susu"], abs=0.001):
            break
        time.sleep(3)
    assert stk["Susu"] == pytest.approx(seb_stok["Susu"], abs=0.001)  # restok − order = net 0
    assert stk["kopi"] == pytest.approx(seb_stok["kopi"] - RESEP_KOPI, abs=0.001)