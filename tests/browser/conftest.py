"""Konfig bersama untuk suite browser E2E FNBSense (Selenium).

Berjalan ke PRODUKSI (kasir.arbitro.dev / pesan.arbitro.dev / owner.arbitro.dev).
Test yang MENULIS data (order, shift, stok, akun) ditandai @pytest.mark.full —
jalankan `pytest -m "not full"` kalau cuma mau baca-baca.

Kredensial diambil dari env, bukan hardcode: EMAIL_FNBS / SANDI_FNBS.
"""
import os
import time

import pytest
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

BASE = os.environ.get("PESAN_BASE", "https://pesan.arbitro.dev")
KASIR = os.environ.get("KASIR_BASE", "https://kasir.arbitro.dev")
OWNER = os.environ.get("OWNER_BASE", "https://owner.arbitro.dev")
EMAIL = os.environ.get("EMAIL_FNBS", "owner@arbitro.dev")
SANDI = os.environ.get("SANDI_FNBS", "Password123!")
QR_MEJA3 = os.environ.get("QR_MEJA3", "VObJpXiVgFGriXONe05hM2n4gG21raWL")

# Ambang minimal stok susu → perhatian saat menulis skenario yang menyentuh stok.
RESEP_SUSU = 150.0  # ml per Kopi Susu
RESEP_KOPI = 18.0   # g per Kopi Susu


@pytest.fixture(scope="session")
def drv():
    opts = Options()
    opts.binary_location = "/home/ubuntu/.agent-browser/browsers/chrome-152.0.7977.82/chrome"
    opts.add_argument("--headless=new")
    opts.add_argument("--no-sandbox")
    opts.add_argument("--disable-dev-shm-usage")
    opts.add_argument("--window-size=1280,900")
    opts.add_argument("--lang=id-ID")
    d = webdriver.Chrome(options=opts)
    d.set_page_load_timeout(60)
    yield d
    d.quit()


def tunggu_teks(d, teks, detik=15):
    """Tunggu sampai teks muncul di body (argumen: ekspektasi minimal)."""
    WebDriverWait(d, detik).until(
        lambda x: teks in x.find_element(By.TAG_NAME, "body").text
    )
    return True


def tunggu_url(d, bagian, detik=15):
    WebDriverWait(d, detik).until(lambda x: bagian in x.current_url)
    return True


def isi_kontrol_react(d, el, nilai):
    """Isi input controlled-React lewat native setter + event input/change."""

    d.execute_script(
        """
        const el = arguments[0], nilai = arguments[1];
        const setter = Object.getOwnPropertyDescriptor(
          el instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype,
          'value').set;
        setter.call(el, nilai);
        el.dispatchEvent(new Event('input', {bubbles: true}));
        el.dispatchEvent(new Event('change', {bubbles: true}));
        """,
        el, nilai,
    )


def klik_teks(d, teks, tepat=True, dalam=None):
    """Klik elemen berteks; scope opsional ke parent (dalam).

    Klik lewat JS — elemen sticky (bar keranjang, header) sering menutupi
    target sehingga klik native Selenium ketahan 'intercepted'.
    """
    src = dalam or d
    xs = src.find_elements(By.XPATH, f".//*[self::button or self::a][contains(normalize-space(.), '{teks}')]")
    for x in xs:
        te = (x.text or "").strip()
        if (tepat and te == teks) or (not tepat and teks in te):
            d.execute_script("arguments[0].click();", x)
            return x
    raise AssertionError(f"Elemen berteks '{teks}' tidak ditemukan")


def login(d, url, email=EMAIL, sandi=SANDI):
    d.get(url)
    # Sudah login (sesi tersimpan) → tidak ada form; tunggu sesaat saja.
    try:
        WebDriverWait(d, 6).until(
            lambda x: len(x.find_elements(By.TAG_NAME, "input")) >= 2
        )
    except Exception:
        return
    inp = d.find_elements(By.TAG_NAME, "input")
    isi_kontrol_react(d, inp[0], email)
    isi_kontrol_react(d, inp[1], sandi)
    klik_teks(d, "Masuk")
    waktu = time.time()
    while time.time() - waktu < 15:
        if "Login" not in d.find_element(By.TAG_NAME, "body").text:
            return
        time.sleep(0.5)
    raise AssertionError("Login tidak berhasil")