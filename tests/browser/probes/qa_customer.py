"""QA probe B: edge case layar customer — QR rusak, cari, checkout tanpa nama."""
import time

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

opts = Options()
opts.binary_location = "/home/ubuntu/.agent-browser/browsers/chrome-152.0.7977.82/chrome"
opts.add_argument("--headless=new")
opts.add_argument("--no-sandbox")
opts.add_argument("--disable-dev-shm-usage")
opts.set_capability("goog:loggingPrefs", {"browser": "ALL"})

QR = "VObJpXiVgFGriXONe05hM2n4gG21raWL"

d = webdriver.Chrome(options=opts)
try:
    def body():
        return d.find_element(By.TAG_NAME, "body").text[:260].replace("\n", " | ")

    # 1) QR token rusak / tak dikenal
    d.get(f"https://pesan.arbitro.dev/t/{'A'*32}")
    time.sleep(3)
    print("[qr-rusak]", d.current_url, "::", body()[:130])

    # 2) QR token kosong / tanpa token
    d.get("https://pesan.arbitro.dev/t/")
    time.sleep(2.5)
    print("[qr-kosong]", d.current_url, "::", body()[:90])

    # 3) cari produk
    d.get(f"https://pesan.arbitro.dev/t/{QR}")
    time.sleep(2.5)
    inp = [x for x in d.find_elements(By.TAG_NAME, "input") if (x.get_attribute("placeholder") or "").startswith("Cari")]
    if inp:
        d.execute_script(
            """const e=arguments[0];const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;
            s.call(e,'zzzzz'); e.dispatchEvent(new Event('input',{bubbles:true}));""", inp[0])
        time.sleep(1.5)
        b = body()
        print("[cari-kosong]", b[:200])
        d.execute_script(
            """const e=arguments[0];const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;
            s.call(e,'kopi'); e.dispatchEvent(new Event('input',{bubbles:true}));""", inp[0])
        time.sleep(1.5)
        print("[cari-kopi]", body()[:200])
    else:
        print("[cari] kotak cari tidak ada (bukti body:)", body()[:120])

    # 4) checkout tanpa nama — tombol kirim harusnya nonaktif
    d.get(f"https://pesan.arbitro.dev/t/{QR}")
    time.sleep(2.5)
    kartu = d.find_elements(By.XPATH, "//button[contains(normalize-space(.), 'es teh anget')]")
    if kartu:
        d.execute_script("arguments[0].click();", kartu[0])
        time.sleep(1.2)
        tambah = [b for b in d.find_elements(By.TAG_NAME, "button") if "Tambah ke keranjang" in b.text]
        if tambah:
            d.execute_script("arguments[0].click();", tambah[0])
            time.sleep(1.2)
            d.execute_script("arguments[0].click();",
                             d.find_element(By.XPATH, "//a[contains(normalize-space(.), 'Pesan sekarang')]"))
            time.sleep(2)
            pil = d.find_elements(By.XPATH, "//label[contains(normalize-space(.), 'Tunai')]")
            if pil:
                d.execute_script("arguments[0].click();", pil[0])
                time.sleep(0.8)
            kirim = [b for b in d.find_elements(By.TAG_NAME, "button") if "Kirim pesanan" in b.text]
            if kirim:
                print("[tanpa-nama] tombol kirim enabled:", kirim[0].is_enabled())
            else:
                print("[tanpa-nama] tombol kirim tidak ada")

    # 5) halaman order yg tak ada (id acak)
    d.get(f"https://pesan.arbitro.dev/t/{QR}/order/{'0'*36}")
    time.sleep(2.5)
    print("[order-takada]", body()[:150])
finally:
    d.quit()