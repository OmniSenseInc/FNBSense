import { useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { bacaToken } from './api'
import Layout from './Layout'
import LayarAntrean from './LayarAntrean'
import LayarDapur from './LayarDapur'
import LayarLogin from './LayarLogin'
import LayarNota from './LayarNota'
import LayarNotifikasi from './LayarNotifikasi'
import LayarPos from './LayarPos'
import LayarRiwayat from './LayarRiwayat'
import LayarSandi from './LayarSandi'
import LayarShift from './LayarShift'
import LayarStok from './LayarStok'

/**
 * App POS — layar kasir. Hanya transaksi & operasional harian: antrean, meja,
 * dapur, nota, shift, riwayat, stok. Laporan/menu/bahan/karyawan sudah pindah
 * ke app OWNER.
 */
export default function App() {
  const [masuk, setMasuk] = useState(() => bacaToken() !== null)
  const keluar = () => setMasuk(false)

  return (
    <BrowserRouter>
      {masuk ? (
        <Routes>
          <Route element={<Layout onKeluar={keluar} />}>
            <Route path="/" element={<LayarAntrean onKeluar={keluar} />} />
            <Route path="/antrean" element={<LayarAntrean onKeluar={keluar} />} />
            <Route path="/pos" element={<LayarPos onKeluar={keluar} />} />
            <Route path="/dapur" element={<LayarDapur onKeluar={keluar} />} />
            <Route path="/nota/:id" element={<LayarNota onKeluar={keluar} />} />
            <Route path="/notifikasi" element={<LayarNotifikasi onKeluar={keluar} />} />
            <Route path="/riwayat" element={<LayarRiwayat onKeluar={keluar} />} />
            <Route path="/shift" element={<LayarShift onKeluar={keluar} />} />
            <Route path="/stok" element={<LayarStok onKeluar={keluar} />} />
            <Route path="/sandi" element={<LayarSandi onKeluar={keluar} />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      ) : (
        <LayarLogin onMasuk={() => setMasuk(true)} />
      )}
    </BrowserRouter>
  )
}
