import { useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { bacaToken } from './api'
import Layout from './Layout'
import LayarBahan from './LayarBahan'
import LayarBatal from './LayarBatal'
import LayarDashboard from './LayarDashboard'
import LayarLaporan from './LayarLaporan'
import LayarLogin from './LayarLogin'
import LayarMenu from './LayarMenu'
import LayarMeja from './LayarMeja'
import LayarPromo from './LayarPromo'
import LayarPengeluaran from './LayarPengeluaran'
import LayarSandi from './LayarSandi'
import LayarSetelan from './LayarSetelan'
import LayarStaf from './LayarStaf'
import LayarStok from './LayarStok'

/**
 * App OWNER — dasbor pengelola. Semua layar di sini mengelola data usaha
 * (laporan, menu, bahan, karyawan, setelan). Kasir TIDAK punya akses ke app ini.
 */
export default function App() {
  const [masuk, setMasuk] = useState(() => bacaToken() !== null)
  const keluar = () => setMasuk(false)

  return (
    <BrowserRouter>
      {masuk ? (
        <Routes>
          <Route element={<Layout onKeluar={keluar} />}>
            <Route path="/" element={<LayarDashboard onKeluar={keluar} />} />
            <Route path="/laporan" element={<LayarLaporan onKeluar={keluar} />} />
            <Route path="/pengeluaran" element={<LayarPengeluaran onKeluar={keluar} />} />
            <Route path="/menu" element={<LayarMenu onKeluar={keluar} />} />
            <Route path="/meja" element={<LayarMeja onKeluar={keluar} />} />
            <Route path="/bahan" element={<LayarBahan onKeluar={keluar} />} />
            <Route path="/stok" element={<LayarStok onKeluar={keluar} />} />
            <Route path="/promo" element={<LayarPromo onKeluar={keluar} />} />
            <Route path="/batal" element={<LayarBatal onKeluar={keluar} />} />
            <Route path="/staf" element={<LayarStaf onKeluar={keluar} />} />
            <Route path="/sandi" element={<LayarSandi onKeluar={keluar} />} />
            <Route path="/setelan" element={<LayarSetelan onKeluar={keluar} />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      ) : (
        <LayarLogin onMasuk={() => setMasuk(true)} />
      )}
    </BrowserRouter>
  )
}
