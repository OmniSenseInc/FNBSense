import { BrowserRouter, Route, Routes } from 'react-router'
import HalamanMenu from './HalamanMenu'
import HalamanRingkasan from './HalamanRingkasan'
import HalamanStatus from './HalamanStatus'
import LayoutMeja from './LayoutMeja'

/**
 * Kerangka rute app pelanggan. Sengaja tipis — semua isi ada di halamannya.
 *
 * qrToken diambil dari URL, bukan disimpan di state: pelanggan bisa refresh,
 * menutup tab lalu membukanya lagi dari riwayat, atau mengirim tautannya ke
 * teman semeja. Semuanya jalan karena mejanya tertulis di alamat.
 */
export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        {/* Rute induk memegang meja + menu + keranjang; anak-anaknya cuma
            layar. Tiap layar punya alamat sendiri supaya refresh, tombol
            back HP, dan berbagi tautan berperilaku sebagaimana mestinya. */}
        <Route path="/t/:qrToken" element={<LayoutMeja />}>
          <Route index element={<HalamanMenu />} />
          <Route path="pesan" element={<HalamanRingkasan />} />
        </Route>
        {/* Alamat status bisa di-bookmark & di-refresh: pelanggan menunggu
            pesanannya, HP-nya bisa mati layar atau tab-nya tertutup.

            Mejanya ikut di alamat supaya tombol "kembali ke menu" punya tujuan.
            Alternatifnya — server mengirim qr_token di respons status — DITOLAK:
            token itu kredensial cetak yang membuka meja bagi siapa pun yang
            memegangnya, dan mengirimkannya ke HP pelanggan persis melahirkan
            masalah "URL meja dipakai orang luar" yang sedang kita hindari.

            Sengaja BUKAN anak LayoutMeja walau alamatnya bersarang: halaman
            status tak butuh menu, dan menjadikannya anak berarti tiap kali
            layar ini dibuka seluruh katalog ikut diunduh tanpa dipakai. */}
        <Route path="/t/:qrToken/order/:id" element={<HalamanStatus />} />
        {/* Buka alamat kosong = belum scan apa pun. Jangan tampilkan layar
            putih; beri tahu apa yang harus dilakukan. */}
        <Route
          path="*"
          element={
            <div className="mx-auto max-w-md px-4 py-16 text-center">
              <p className="text-base font-semibold">Scan QR di meja</p>
              <p className="mt-1 text-sm text-slate-600">
                Menu terbuka otomatis setelah QR di meja kamu dipindai.
              </p>
            </div>
          }
        />
      </Routes>
    </BrowserRouter>
  )
}
