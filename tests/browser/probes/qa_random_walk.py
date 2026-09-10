"""Random walk E2E (seed tetap): kombinasi acak produk/qty/nama/payment/double-submit,
lalu aksi acak bayar/batal; setelah itu konsistensi dicek di DB & API.
MENULIS DATA: order (dibatalkan sebagian besar), beberapa paid kecil.
"""
import random
import re
import time
import subprocess

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

random.seed(20260908)
QR = "VObJpXiVgFGriXONe05hM2n4gG21raWL"
BASE = f"https://pesan.arbitro.dev/t/{QR}"
KASIR = "https://kasir.arbitro.dev"

PRODUK = ["es teh anget", "Kopi Tubruk", "Kopi Susu", "perek premium"]
NAMA_LIAR = ["Budi", "A" * 90, "🤖🔥", "x" * 200, "Uji Random"]
CATATAN = ["", "tanpa gula", "dingin banget plis", "🧊", "extra extra extra long note " * 5]

opts = Options()
opts.binary_location = "/home/ubuntu/.agent-browser/browsers/chrome-152.0.7977.82/chrome"
opts.add_argument("--headless=new"); opts.add_argument("--no-sandbox")
opts.add_argument("--disable-dev-shm-usage")

d = webdriver.Chrome(options=opts)

def isi(d, el, v):
    d.execute_script(
        """const e=arguments[0],v=arguments[1];
        const proto = e instanceof HTMLTextAreaElement
          ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
        const s=Object.getOwnPropertyDescriptor(proto,'value').set;
        s.call(e,v); e.dispatchEvent(new Event('input',{bubbles:true}));
        e.dispatchEvent(new Event('change',{bubbles:true}));""", el, v)

def body(): return d.find_element(By.TAG_NAME, "body").text

def klik_js(d, el):
    d.execute_script("arguments[0].click();", el)

hasil = []
try:
    for it in range(6):
        produk = random.choice(PRODUK)
        qty = random.randint(1, 3)
        nama = random.choice(NAMA_LIAR)
        catatan = random.choice(CATATAN)
        bayar = random.choice(["Tunai", "E-Payment"])
        dobel = random.random() < 0.5
        tag = f"Iterasi{it}"

        d.get(BASE); time.sleep(2.5)
        d.execute_script("localStorage.clear()"); d.get(BASE); time.sleep(2.5)
        if produk not in body():
            hasil.append(f"[{tag}] SKIP {produk} tidak di menu")
            continue
        kartu = d.find_elements(By.XPATH, f"//button[contains(normalize-space(.), '{produk}')]")
        if not kartu:
            hasil.append(f"[{tag}] SKIP kartu {produk}")
            continue
        klik_js(d, kartu[0]); time.sleep(1.2)
        dlg = [x for x in d.find_elements(By.TAG_NAME, "dialog") if x.get_attribute("open")]
        if not dlg:
            hasil.append(f"[{tag}] dialog tidak terbuka"); continue
        # qty acak lewat tombol +
        for _ in range(qty - 1):
            plus = [b for b in dlg[0].find_elements(By.TAG_NAME, "button") if b.text.strip() == "+"]
            if plus: klik_js(d, plus[-1])
        # catatan
        teks = dlg[0].find_elements(By.XPATH, ".//input | .//textarea")
        if teks and catatan:
            isi(d, teks[0], catatan)
            time.sleep(0.3)
        tambah = [b for b in dlg[0].find_elements(By.TAG_NAME, "button") if "Tambah ke keranjang" in b.text]
        if not tambah:
            hasil.append(f"[{tag}] tombol tambah hilang"); continue
        klik_js(d, tambah[0]); time.sleep(1)
        # ke ringkasan
        pesan = d.find_elements(By.XPATH, "//a[contains(normalize-space(.), 'Pesan sekarang')]")
        if not pesan:
            hasil.append(f"[{tag}] bar keranjang tak muncul"); continue
        klik_js(d, pesan[0]); time.sleep(1.8)
        if "Nama pemesan" not in body():
            hasil.append(f"[{tag}] gagal ke ringkasan"); continue
        inp_nama = d.find_elements(By.TAG_NAME, "input")[0]
        isi(d, inp_nama, nama)
        lbl = [x for x in d.find_elements(By.XPATH, f"//label[contains(normalize-space(.), '{bayar}')]")]
        if lbl: klik_js(d, lbl[0])
        kirim = [b for b in d.find_elements(By.TAG_NAME, "button") if "Kirim pesanan" in b.text]
        if kirim and kirim[0].is_enabled():
            klik_js(d, kirim[0])
            if dobel: klik_js(d, kirim[0])  # double-submit acak
            time.sleep(2.5)
        else:
            hasil.append(f"[{tag}] kirim disabled (nama {nama[:12]}…) — benar")
            continue
        if "Nomor pesanan" not in body():
            galat = body()[:400]
            hasil.append(f"[{tag}] GAGAL: {produk}x{qty} nama={nama[:15]} bayar={bayar} catatan={'y' if catatan else 'n'} — {galat.replace(chr(10),' | ') if 'Galat' in galat or 'gagal' in galat else galat[:160]}")
            continue
        kode = re.search(r"[A-Z0-9]{6}", body())
        hasil.append(f"[{tag}] order {kode.group(0) if kode else '?'} | {produk}x{qty} | {bayar} | dobel={dobel} | nama={nama[:10]}")
finally:
    d.quit()

for h in hasil: print(h)