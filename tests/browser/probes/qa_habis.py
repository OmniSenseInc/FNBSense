"""QA probe B2: label Habis hari ini — kategori vs pencarian; apakah bisa dipesan."""
import time

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

opts = Options()
opts.binary_location = "/home/ubuntu/.agent-browser/browsers/chrome-152.0.7977.82/chrome"
opts.add_argument("--headless=new")
opts.add_argument("--no-sandbox")
opts.add_argument("--disable-dev-shm-usage")
QR = "VObJpXiVgFGriXONe05hM2n4gG21raWL"
d = webdriver.Chrome(options=opts)
try:
    d.get(f"https://pesan.arbitro.dev/t/{QR}")
    time.sleep(3)
    teks = d.find_element(By.TAG_NAME, "body").text
    # kartu Kopi Susu di kategori
    print("[kategori] ada 'Habis':", "Kopi Susu" in teks and "Habis" in teks)
    i = teks.find("Kopi Susu")
    print("  konteks kategori:", teks[max(0, i-60):i+90].replace("\n", " | "))

    # via pencarian
    inp = [x for x in d.find_elements(By.TAG_NAME, "input") if (x.get_attribute("placeholder") or "").startswith("Cari")]
    d.execute_script(
        """const e=arguments[0];const s=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;
        s.call(e,'susu'); e.dispatchEvent(new Event('input',{bubbles:true}));""", inp[0])
    time.sleep(2)
    teks2 = d.find_element(By.TAG_NAME, "body").text
    j = teks2.find("Kopi Susu")
    print("[cari] konteks hasil:", teks2[max(0, j-50):j+90].replace("\n", " | "))

    # coba buka dialog Kopi Susu dari hasil cari → ada tombol tambah?
    kartu = d.find_elements(By.XPATH, "//button[contains(normalize-space(.), 'Kopi Susu')]")
    if kartu:
        d.execute_script("arguments[0].click();", kartu[0])
        time.sleep(1.5)
        dlgs = d.find_elements(By.TAG_NAME, "dialog")
        for dl in dlgs:
            if dl.get_attribute("open"):
                print("[dialog] isi:", (dl.text or "").replace("\n", " | ")[:200])
        tambah = [b for b in d.find_elements(By.TAG_NAME, "button") if "Tambah ke keranjang" in b.text]
        print("[dialog] tombol tambah ada:", bool(tambah))
finally:
    d.quit()