import { useEffect, useState } from 'react'
import { Link, Outlet, useLocation } from 'react-router'
import {
  Ban,
  FlaskConical,
  Home,
  KeyRound,
  LayoutGrid,
  LogOut,
  MoreHorizontal,
  Package,
  Settings,
  Tag,
  TrendingUp,
  Users,
  UtensilsCrossed,
  Wallet,
} from 'lucide-react'
import { cekAkunAktif, logout, peranSaya } from './api'

/**
 * Bingkai app OWNER — TINTA (kertas & tinta).
 * Navigasi bawah fokus ke pengelolaan: beranda, laporan, menu, bahan —
 * sisanya (pengeluaran, stok, karyawan, pengaturan, ubah sandi) di panel
 * "Lainnya". Ikon dipertahankan; item aktif jadi blok tinta (stempel).
 */
export default function Layout({ onKeluar }: { onKeluar: () => void }) {
  const lokasi = useLocation()
  const [lainnya, setLainnya] = useState(false)
  // Manager cuma lihat; menu owner-only disembunyikan dari "Lainnya" supaya
  // dia tak mengetuk tombol yang ditolak server. Penjaga tetap role:owner.
  const owner = peranSaya() === 'owner'

  const keluar = async () => {
    await logout()
    onKeluar()
  }

  // Akun yang dihapus/dinonaktifkan owner harus TER-LOGOUT, bukan menunggu
  // token kedaluwarsa (sampai 15 menit). Token stateless: yang tahu akun sudah
  // mati cuma IAM — jadi tanya `/me` berkala; 403 = akun mati → keluar sekarang.
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

  const aktif = (jalur: string) => lokasi.pathname === jalur

  const item = (ke: string, label: string, Ikon: typeof Home) => {
    const on = aktif(ke)
    return (
      <Link
        to={ke}
        className={`flex flex-col items-center gap-1 py-2 px-3 rounded-lg text-[11px] font-medium transition-colors ${
          on ? 'bg-ink text-paper' : 'text-muted hover:text-ink'
        }`}
      >
        <Ikon size={20} strokeWidth={on ? 2.25 : 1.75} />
        <span>{label}</span>
      </Link>
    )
  }

  const jalurUtama = ['/', '/laporan', '/menu', '/bahan']
  const bukanUtama = !jalurUtama.includes(lokasi.pathname)

  return (
    <div className="min-h-screen flex flex-col bg-paper text-ink">
      {/* —— bar atas ——————————————————————————————— */}
      <header className="sticky top-0 z-30 bg-paper border-b border-ink h-14 px-4 flex items-center justify-between shrink-0">
        <h1 className="text-[15px] font-bold tracking-tight text-ink">
          FNBSense <span className="text-muted font-medium">· Owner</span>
        </h1>
        <button
          onClick={keluar}
          aria-label="Keluar"
          className="p-2 rounded-md text-muted hover:bg-paper hover:text-ink"
        >
          <LogOut size={19} strokeWidth={1.75} />
        </button>
      </header>

      {/* —— isi layar ——————————————————————————————— */}
      <main className="flex-1 pb-24">
        <Outlet />
      </main>

      {/* —— navigasi bawah —————————————————————————— */}
      <nav className="fixed bottom-0 inset-x-0 z-30 bg-surface border-t border-ink px-2 pt-1.5 pb-[env(safe-area-inset-bottom,8px)] flex justify-around items-center">
        {item('/', 'Beranda', Home)}
        {item('/laporan', 'Laporan', TrendingUp)}
        {item('/menu', 'Menu', UtensilsCrossed)}
        {item('/bahan', 'Bahan', FlaskConical)}
        <button
          onClick={() => setLainnya(!lainnya)}
          className={`flex flex-col items-center gap-1 py-2 px-3 rounded-lg text-[11px] font-medium transition-colors ${
            lainnya || bukanUtama ? 'bg-ink text-paper' : 'text-muted hover:text-ink'
          }`}
        >
          <MoreHorizontal size={20} strokeWidth={1.75} />
          <span>Lainnya</span>
        </button>
      </nav>

      {/* —— panel "Lainnya" ————————————————————————— */}
      {lainnya && (
        <div className="fixed inset-0 z-40 flex" onClick={() => setLainnya(false)}>
          <div className="flex-1 bg-ink/40" />
          <div
            className="w-64 max-w-[80vw] bg-surface h-full overflow-y-auto flex flex-col p-4 border-l border-line"
            onClick={(e) => e.stopPropagation()}
          >
            <p className="text-[11px] font-semibold text-faint uppercase tracking-wider mb-3">Menu</p>
            <div className="flex flex-col gap-0.5">
              {[
                ...(owner ? [{ ke: '/pengeluaran', label: 'Pengeluaran', Ikon: Wallet }] : []),
                ...(owner ? [{ ke: '/batal', label: 'Pembatalan', Ikon: Ban }] : []),
                { ke: '/stok', label: 'Stok', Ikon: Package },
                ...(owner ? [{ ke: '/meja', label: 'Meja', Ikon: LayoutGrid }] : []),
                ...(owner ? [{ ke: '/promo', label: 'Promo', Ikon: Tag }] : []),
                ...(owner ? [{ ke: '/staf', label: 'Karyawan', Ikon: Users }] : []),
                ...(owner ? [{ ke: '/setelan', label: 'Pengaturan', Ikon: Settings }] : []),
                { ke: '/sandi', label: 'Ubah sandi', Ikon: KeyRound },
              ].map(({ ke, label, Ikon }) => (
                <Link
                  key={ke}
                  to={ke}
                  onClick={() => setLainnya(false)}
                  className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm transition-colors ${
                    aktif(ke) ? 'bg-ink text-paper font-semibold' : 'text-ink hover:bg-paper'
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
