import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],

  /**
   * Dev server dibuka ke jaringan supaya app-nya bisa dicoba dari HP sungguhan
   * — QR order cuma jujur diuji di HP, bukan di jendela browser yang disempitkan.
   *
   * Backend TIDAK ikut dibuka. HP hanya bicara ke port ini; Vite yang
   * meneruskan ke Laravel di 127.0.0.1. Tiga akibat yang semuanya kita mau:
   *
   * 1. Ordering & Catalog tetap tertutup dari Wi-Fi. Di kafe atau kos, jaringan
   *    itu bukan milik kita — tak ada alasan memaparkan API ke sana.
   * 2. Cuma SATU port yang perlu lolos firewall Windows, bukan tiga.
   * 3. Bagi HP semuanya satu origin, jadi CORS tak pernah ikut bermain. Ini
   *    juga bentuk produksi nanti (satu domain lewat Traefik), jadi jalur yang
   *    kita uji hari ini = jalur yang benar-benar dipakai.
   *
   * Awalannya harus cocok dengan VITE_ORDERING_URL / VITE_CATALOG_URL di
   * .env.local. Kalau salah satu diubah, yang lain ikut atau menu gagal dimuat.
   */
  server: {
    host: true,
    // Vite menolak permintaan yang datang dengan nama host tak dikenal — pagar
    // yang dipasang setelah ada celah di dev server, dan pagar itu benar.
    // Konsekuensinya: alamat tunnel (uji dari HP sungguhan lewat HTTPS) ikut
    // ditolak dengan "Blocked request", yang mudah disalahartikan sebagai
    // tunnelnya rusak. Awalan titik mengizinkan seluruh subdomainnya, sebab
    // alamat tunnel gratisan berganti tiap kali dinyalakan.
    //
    // Hanya berlaku untuk `npm run dev`. Produksi tak memakai server ini sama
    // sekali — image-nya nginx yang melayani berkas statis.
    allowedHosts: ['.trycloudflare.com'],
    proxy: {
      '/ordering': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/ordering/, ''),
      },
      '/catalog': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/catalog/, ''),
      },
    },
  },
})
