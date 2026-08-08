import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/**
 * Pola proxy-nya sama persis dengan apps/customer, alasannya juga sama:
 * browser hanya bicara ke port ini, Vite yang meneruskan ke Laravel di
 * 127.0.0.1. Backend tak ikut terbuka ke jaringan, dan bagi browser semuanya
 * satu origin sehingga CORS tak pernah ikut bermain.
 *
 * Bedanya: kasir bicara ke DUA service lain — IAM (login/refresh) dan Ordering
 * (antrean & konfirmasi bayar). Catalog tak dipakai; kasir tak menyusun pesanan.
 *
 * Port dev sengaja beda dari apps/customer (5173) supaya dua app bisa hidup
 * bersamaan saat mencoba alur penuh: memesan dari HP, mengonfirmasi dari laptop.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],

  server: {
    host: true,
    port: 5174,
    // Alasannya sama persis dengan apps/customer — lihat catatan di sana.
    allowedHosts: ['.trycloudflare.com'],
    proxy: {
      '/iam': {
        target: 'http://127.0.0.1:8002',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/iam/, ''),
      },
      '/ordering': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/ordering/, ''),
      },
      // Inbox peringatan stok (F8a). Service ketiga yang disentuh kasir.
      '/notification': {
        target: 'http://127.0.0.1:8007',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/notification/, ''),
      },
      // Saldo stok. Kasir boleh MELIHAT, tak boleh mengubah — pagarnya
      // `role:owner` di Inventory untuk restock/adjust, bukan di sini.
      '/inventory': {
        target: 'http://127.0.0.1:8003',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/inventory/, ''),
      },
      // Menu (kategori & produk), owner-only. Catatan lama di kepala berkas
      // ini benar untuk KASIR — dan gugur begitu owner ikut memakai app yang
      // sama: dialah yang menyusun menu, bukan yang menerima uang.
      '/catalog': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/catalog/, ''),
      },
      // Shift kasir: buka/tutup laci + laporan X/Z. Service kelima yang
      // disentuh app ini, dan yang pertama menyentuh uang tunai.
      '/finance': {
        target: 'http://127.0.0.1:8004',
        changeOrigin: true,
        rewrite: (jalur) => jalur.replace(/^\/finance/, ''),
      },
    },
  },
})
