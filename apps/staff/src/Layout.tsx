import { useEffect, useState } from 'react'
import { Link, Outlet, useLocation } from 'react-router'
import {
  Bell,
  ChefHat,
  History,
  Home,
  KeyRound,
  LogOut,
  MoreHorizontal,
  Package,
  ShoppingCart,
  Wallet,
} from 'lucide-react'
import { bacaNama, cekAkunAktif, hitungBelumDibaca, logout, peranSaya } from './api'

/** Label peran untuk header: \"Kasir: Tina\", \"Manager: Budi\", dst. */
const LABEL_PERAN: Record<string, string> = {
  owner: 'Owner',
  manager: 'Manager',
  cashier: 'Kasir',
}

/**
 * Bingkai app POS (kasir). Navigasi bawah fokus ke transaksi & operasional:
 * beranda, kasir, dapur, stok, shift — sisanya di panel "Lainnya".
 */
export default function Layout({ onKeluar }: { onKeluar: () => void }) {
  const lokasi = useLocation()
  const [belumDibaca, setBelumDibaca] = useState(0)
  const [lainnya, setLainnya] = useState(false)
  // Siapa yang login — dibaca sekali saat Layout ter-mount (Layout = bingkai
  // persisten app, tak di-unmount selama sesi). Nama dari localStorage (respons
  // login), peran dari klaim JWT (selalu segar usai penukaran token).
  const nama = bacaNama()
  const peran = peranSaya()
  const labelPeran = peran ? (LABEL_PERAN[peran] ?? peran) : null

  useEffect(() => {
    const muat = () => hitungBelumDibaca().then(setBelumDibaca).catch(() => {})
    muat()
    const id = setInterval(muat, 30_000)
    return () => clearInterval(id)
  }, [])

  // Akun yang dihapus/dinonaktifkan owner harus TER-LOGOUT, bukan menunggu
  // token kedaluwarsa (sampai 15 menit). Token stateless: yang tahu akun sudah
  // mati cuma IAM — jadi tanya `/me` berkala, sejalan dengan polling notifikasi
  // di atas (jeda sama; biaya satu permintaan ringan per 30 detik tak apa).
  useEffect(() => {
    const cek = () =>
      cekAkunAktif()
        .then((hidup) => {
          if (!hidup) {
            void logout()
            onKeluar()
          }
        })
        .catch(() => {})
    cek()
    const id = setInterval(cek, 30_000)
    return () => clearInterval(id)
  }, [onKeluar])

  const keluar = async () => {
    await logout()
    onKeluar()
  }

  const aktif = (jalur: string) => lokasi.pathname === jalur

  const item = (ke: string, label: string, Ikon: typeof Home) => {
    const on = aktif(ke)
    return (
      <Link
        to={ke}
        className={`flex flex-col items-center gap-1 py-2 px-3 rounded-xl text-[11px] font-medium transition-colors ${
          on ? 'text-sage-700' : 'text-stone-400 hover:text-stone-600'
        }`}
      >
        <Ikon size={20} strokeWidth={on ? 2.25 : 1.75} />
        <span>{label}</span>
      </Link>
    )
  }

  const jalurUtama = ['/', '/antrean', '/pos', '/dapur', '/stok', '/shift']
  const bukanUtama = !jalurUtama.includes(lokasi.pathname)

  return (
    <div className="min-h-screen flex flex-col bg-stone-50 text-stone-800">
      {/* —— bar atas ——————————————————————————————— */}
      <header className="sticky top-0 z-30 bg-white/90 backdrop-blur border-b border-stone-200 h-14 px-4 flex items-center justify-between shrink-0">
        <h1 className="text-[15px] font-bold tracking-tight text-stone-800">
          FNBSense
          {nama && (
            <span className="font-medium text-stone-500">
              {' '}· {labelPeran ? `${labelPeran}: ` : ''}{nama}
            </span>
          )}
        </h1>
        <div className="flex items-center gap-1">
          <Link
            to="/notifikasi"
            aria-label="Notifikasi"
            className="relative p-2 rounded-full text-stone-500 hover:bg-stone-100"
          >
            <Bell size={19} strokeWidth={1.75} />
            {belumDibaca > 0 && (
              <span className="absolute top-1 right-1 min-w-4 h-4 rounded-full bg-sage-600 text-[10px] font-bold text-white flex items-center justify-center px-1">
                {belumDibaca > 9 ? '9+' : belumDibaca}
              </span>
            )}
          </Link>
          <button
            onClick={keluar}
            aria-label="Keluar"
            className="p-2 rounded-full text-stone-400 hover:bg-stone-100 hover:text-stone-700"
          >
            <LogOut size={19} strokeWidth={1.75} />
          </button>
        </div>
      </header>

      {/* —— isi layar ——————————————————————————————— */}
      <main className="flex-1 pb-24">
        <Outlet />
      </main>

      {/* —— navigasi bawah —————————————————————————— */}
      <nav className="fixed bottom-0 inset-x-0 z-30 bg-white/95 backdrop-blur border-t border-stone-200 px-2 pt-1 pb-[env(safe-area-inset-bottom,8px)] flex justify-around items-center">
        {item('/', 'Beranda', Home)}
        {item('/pos', 'Kasir', ShoppingCart)}
        {item('/dapur', 'Dapur', ChefHat)}
        {item('/stok', 'Stok', Package)}
        {item('/shift', 'Shift', Wallet)}
        <button
          onClick={() => setLainnya(!lainnya)}
          className={`flex flex-col items-center gap-1 py-2 px-3 rounded-xl text-[11px] font-medium transition-colors ${
            lainnya || bukanUtama ? 'text-sage-700' : 'text-stone-400 hover:text-stone-600'
          }`}
        >
          <MoreHorizontal size={20} strokeWidth={1.75} />
          <span>Lainnya</span>
        </button>
      </nav>

      {/* —— panel "Lainnya" ————————————————————————— */}
      {lainnya && (
        <div className="fixed inset-0 z-40 flex" onClick={() => setLainnya(false)}>
          <div className="flex-1 bg-stone-900/40" />
          <div
            className="w-64 max-w-[80vw] bg-white h-full shadow-xl overflow-y-auto flex flex-col p-4"
            onClick={(e) => e.stopPropagation()}
          >
            <p className="text-xs font-semibold text-stone-400 mb-3">Menu</p>
            <div className="flex flex-col gap-0.5">
              {[
                { ke: '/riwayat', label: 'Riwayat', Ikon: History },
                { ke: '/sandi', label: 'Ubah sandi', Ikon: KeyRound },
              ].map(({ ke, label, Ikon }) => (
                <Link
                  key={ke}
                  to={ke}
                  onClick={() => setLainnya(false)}
                  className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-colors ${
                    aktif(ke)
                      ? 'bg-sage-50 text-sage-700 font-semibold'
                      : 'text-stone-700 hover:bg-stone-100'
                  }`}
                >
                  <Ikon size={18} strokeWidth={1.75} />
                  <span>{label}</span>
                </Link>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
