"""QA probe A: validasi login kasir + owner (profil bersih, console terpantau)."""
import json
import time

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

opts = Options()
opts.binary_location = "/home/ubuntu/.agent-browser/browsers/chrome-152.0.7977.82/chrome"
opts.add_argument("--headless=new")
opts.add_argument("--no-sandbox")
opts.add_argument("--disable-dev-shm-usage")
opts.add_argument("--window-size=1280,900")
opts.set_capability("goog:loggingPrefs", {"browser": "ALL"})


def isi(d, i, v):
    d.execute_script(
        """const e=arguments[0],v=arguments[1];
        const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;
        s.call(e,v); e.dispatchEvent(new Event('input',{bubbles:true}));""", i, v)


def coba_login(d, url, email, sandi, label):
    d.get(url)
    time.sleep(2.5)
    ins = d.find_elements(By.TAG_NAME, "input")
    if len(ins) < 2:
        print(f"[{label}] BUKAN FORM LOGIN (sudah masuk?) -> {len(ins)} input")
        return
    isi(d, ins[0], email)
    isi(d, ins[1], sandi)
    b = [x for x in d.find_elements(By.TAG_NAME, "button") if x.text.strip() == "Masuk"]
    if not b:
        print(f"[{label}] tombol Masuk tidak ada")
        return
    d.execute_script("arguments[0].click();", b[-1])
    time.sleep(2.5)
    teks = d.find_element(By.TAG_NAME, "body").text[:200].replace("\n", " | ")
    print(f"[{label}] -> {teks[:160]}")
    return teks


d = webdriver.Chrome(options=opts)
try:
    print("=== KASIR ===")
    coba_login(d, "https://kasir.arbitro.dev", "", "", "kosong")
    coba_login(d, "https://kasir.arbitro.dev", "owner@arbitro.dev", "SALAH123!", "pw-salah")
    coba_login(d, "https://kasir.arbitro.dev", "orang@salah.dev", "Password123!", "email-salah")
    print("=== rate limit (6x salah) ===")
    for i in range(6):
        coba_login(d, "https://kasir.arbitro.dev", f"x{i}@y.dev", "SALAHnya!", f"percobaan-{i+1}")
        if "Terlalu" in (d.find_element(By.TAG_NAME, "body").text):
            print("  → RATE LIMIT MUNCUL di percobaan", i + 1)
            break
    errs = d.get_log("browser")
    print("=== console errors ===")
    for e in errs:
        if e["level"] == "SEVERE":
            print("  SEVERE:", e["message"][:180])
finally:
    d.quit()