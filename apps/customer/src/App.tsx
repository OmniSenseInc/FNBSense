import { BrowserRouter, Route, Routes } from 'react-router'
import HalamanMenu from './HalamanMenu'
import HalamanStatus from './HalamanStatus'

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
        <Route path="/t/:qrToken" element={<HalamanMenu />} />
        {/* Alamat status bisa di-bookmark & di-refresh: pelanggan menunggu
            pesanannya, HP-nya bisa mati layar atau tab-nya tertutup. */}
        <Route path="/order/:id" element={<HalamanStatus />} />
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
