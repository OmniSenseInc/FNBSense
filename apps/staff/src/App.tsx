import { useState } from 'react'
import { bacaToken, type Pesanan } from './api'
import LayarAntrean from './LayarAntrean'
import LayarLogin from './LayarLogin'
import LayarNota from './LayarNota'

/**
 * Kerangka app kasir. Sengaja TANPA router: hari ini cuma ada satu layar kerja,
 * dan "sudah login atau belum" bukan alamat yang perlu bisa dibagikan atau
 * di-bookmark. Router masuk bersama layar /kds nanti, saat benar-benar ada dua
 * tujuan yang berbeda.
 */
export default function App() {
  // Dibaca sekali saat mount: kasir yang me-refresh tab di tengah shift tak
  // perlu login ulang. Token yang ternyata sudah mati ketahuan pada permintaan
  // pertama, dan LayarAntrean yang memulangkannya ke sini.
  const [masuk, setMasuk] = useState(() => bacaToken() !== null)

  // Pesanan yang baru saja lunas, menunggu dicetak. State, bukan alamat:
  // nota cuma relevan beberapa detik setelah konfirmasi, dan menambah router
  // untuk satu tujuan itu ongkos yang belum terbayar. Akibatnya yang diterima:
  // me-refresh browser saat di layar nota kembali ke antrean.
  const [nota, setNota] = useState<Pesanan | null>(null)

  if (!masuk) {
    return <LayarLogin onMasuk={() => setMasuk(true)} />
  }

  if (nota !== null) {
    return <LayarNota pesanan={nota} onKembali={() => setNota(null)} />
  }

  return (
    <LayarAntrean
      onKeluar={() => {
        // Nota ikut dibuang saat sesi berakhir: ia memuat nama pelanggan dan
        // rincian belanjanya, dan layar kasir sering ditinggal tanpa penjaga.
        setNota(null)
        setMasuk(false)
      }}
      onDibayar={setNota}
    />
  )
}
