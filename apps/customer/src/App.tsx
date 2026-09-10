import { BrowserRouter, Route, Routes } from 'react-router'
import { Coffee, ScanLine } from 'lucide-react'
import { NAMA_KAFE } from './api'
import HalamanMenu from './HalamanMenu'
import HalamanRingkasan from './HalamanRingkasan'
import HalamanStatus from './HalamanStatus'
import LayoutMeja from './LayoutMeja'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/t/:qrToken" element={<LayoutMeja />}>
          <Route index element={<HalamanMenu />} />
          <Route path="pesan" element={<HalamanRingkasan />} />
        </Route>
        <Route path="/t/:qrToken/order/:id" element={<HalamanStatus />} />
        <Route
          path="*"
          element={
            <div className="min-h-screen flex items-center justify-center bg-stone-50 px-6">
              <div className="text-center max-w-sm">
                <div className="mx-auto mb-6 w-24 h-24 rounded-3xl bg-sage-100 flex items-center justify-center">
                  <Coffee size={44} strokeWidth={1.5} className="text-sage-600" />
                </div>
                <h1 className="text-2xl font-bold text-stone-800 mb-2">{NAMA_KAFE}</h1>
                <p className="text-stone-500 text-sm leading-relaxed mb-8">
                  Menu bisa langsung kamu lihat setelah memindai kode QR yang ada di meja kafe.
                </p>
                <div className="inline-flex items-center gap-2.5 px-6 py-3 bg-sage-600 text-white font-semibold rounded-full text-sm">
                  <ScanLine size={18} strokeWidth={2} />
                  <span>Scan QR di meja</span>
                </div>
                <p className="mt-8 text-xs text-stone-400">
                  Tanya kasir kalau kode QR di meja tidak terbaca.
                </p>
              </div>
            </div>
          }
        />
      </Routes>
    </BrowserRouter>
  )
}
