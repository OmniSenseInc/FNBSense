"""Verify: Kopi Susu x2 (stok kurang) → pesan stok habis TAMPAK di UI."""
import time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

opts = Options()
opts.binary_location = "/home/ubuntu/.agent-browser/browsers/chrome-152.0.7977.82/chrome"
opts.add_argument("--headless=new"); opts.add_argument("--no-sandbox")
opts.add_argument("--disable-dev-shm-usage")
d = webdriver.Chrome(options=opts)
try:
    d.get("https://pesan.arbitro.dev/t/VObJpXiVgFGriXONe05hM2n4gG21raWL")
    time.sleep(3)
    kartu = d.find_elements(By.XPATH, "//button[contains(normalize-space(.), 'Kopi Susu')]")[0]
    d.execute_script("arguments[0].click();", kartu)
    time.sleep(1.3)
    dlg = [x for x in d.find_elements(By.TAG_NAME, "dialog") if x.get_attribute("open")][0]
    # qty -> 2
    plus = [b for b in dlg.find_elements(By.TAG_NAME, "button") if b.text.strip() == "+"]
    d.execute_script("arguments[0].click();", plus[-1])
    time.sleep(0.5)
    tambah = [b for b in dlg.find_elements(By.TAG_NAME, "button") if "Tambah ke keranjang" in b.text]
    d.execute_script("arguments[0].click();", tambah[0])
    time.sleep(1)
    d.execute_script("arguments[0].click();",
                     d.find_element(By.XPATH, "//a[contains(normalize-space(.), 'Pesan sekarang')]"))
    time.sleep(2)
    inp = d.find_elements(By.TAG_NAME, "input")[0]
    d.execute_script("""const e=arguments[0],v='Budi';const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;s.call(e,v);e.dispatchEvent(new Event('input',{bubbles:true}));""", inp)
    lbl = d.find_elements(By.XPATH, "//label[contains(normalize-space(.), 'Tunai')]")
    if lbl: d.execute_script("arguments[0].click();", lbl[0])
    time.sleep(0.5)
    kirim = [b for b in d.find_elements(By.TAG_NAME, "button") if "Kirim pesanan" in b.text]
    print("kirim enabled:", kirim[0].is_enabled())
    d.execute_script("arguments[0].click();", kirim[0])
    time.sleep(3)
    teks = d.find_element(By.TAG_NAME, "body").text
    if "sedang habis" in teks or "Bahan untuk" in teks:
        print("✓ PESAN STOK HABIS TAMPAK di UI:", [l for l in teks.split("\n") if "habis" in l or "Bahan" in l][:2])
    else:
        print("? pesan tak ketemu. akhir halaman:", teks[-250:].replace("\n"," | "))
finally:
    d.quit()