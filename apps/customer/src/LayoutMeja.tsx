import { useEffect, useState } from 'react'
import { Outlet, useParams } from 'react-router'
import { ambilMeja, ambilMenu, type Kategori, type Meja } from './api'
import { useKeranjang } from './keranjang'

/** Bungkus pesan satu layar — dipakai untuk memuat dan galat. */
function Pesan({ judul, isi }: { judul: string; isi: string }) {
  return (
    <div className="mx-auto max-w-md px-4 py-16 text-center">
      <p className="text-base font-semibold">{judul}</p>
      <p className="mt-1 text-sm text-slate-600">{isi}</p>
    </div>
  )
}

type Status = 'memuat' | 'siap' | 'galat'

/**
 * Rute induk satu meja: /t/:qrToken.
 *
 * Ada supaya menu dan ringkasan bisa jadi ALAMAT yang berbeda tanpa memuat
 * ulang bahan yang sama. Sebelumnya keduanya satu komponen dengan penanda
 * `tahap` di state — dan state tak punya alamat, jadi refresh di layar
 * ringkasan membuang pelanggan balik ke menu, dan tombol back HP keluar dari
 * app sama sekali.
 */
export default function LayoutMeja() {
  const { qrToken } = useParams<{ qrToken: string }>()

  const [status, setStatus] = useState<Status>('memuat')
  const [meja, setMeja] = useState<Meja | null>(null)
  const [kategori, setKategori] = useState<Kategori[]>([])
  const [isi, setIsi, hapusKeranjang] = useKeranjang(qrToken)

  // DUA panggilan berurutan, bukan satu: `GET /t/{qr}` tak mengembalikan menu,
  // dan tenant_id-nya baru diketahui setelah panggilan pertama selesai.
  useEffect(() => {
    if (!qrToken) return

    // Penanda batal: React StrictMode menjalankan efek dua kali saat dev, dan
    // pelanggan bisa menutup halaman sebelum jaringan selesai. Tanpa ini,
    // hasil yang sudah basi bisa menimpa state.
    let batal = false
    setStatus('memuat')

    ambilMeja(qrToken)
      .then(async (m) => {
        // outlet_id ikut dikirim supaya menunya sekalian membawa penanda habis
        // per produk — satu perjalanan jaringan, bukan dua. Kalau pemeriksaan
        // stok di Catalog gagal, menunya tetap datang utuh tanpa penanda; yang
        // menolak pesanan tetap gerbang di server.
        const k = await ambilMenu(m.tenant_id, m.outlet_id)
        if (batal) return
        setMeja(m)
        setKategori(k)
        setStatus('siap')
      })
      .catch(() => {
        if (!batal) setStatus('galat')
      })

    return () => {
      batal = true
    }
  }, [qrToken])

  if (status === 'memuat') {
    return <Pesan judul="Memuat menu…" isi="Sebentar ya." />
  }

  // meja & qrToken ikut dijaga di sini supaya halaman anak menerima konteks
  // yang sudah pasti lengkap — mereka tak perlu menebak-nebak null.
  if (status === 'galat' || !meja || !qrToken) {
    return (
      <Pesan
        judul="Menu tidak bisa dimuat"
        isi="Periksa koneksi, lalu scan ulang QR di meja. Kalau tetap gagal, panggil kasir."
      />
    )
  }

  return <Outlet context={{ qrToken, meja, kategori, isi, setIsi, hapusKeranjang }} />
}
