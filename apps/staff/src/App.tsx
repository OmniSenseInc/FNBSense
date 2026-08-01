import { useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { bacaToken } from './api'
import LayarAntrean from './LayarAntrean'
import LayarDibuat from './LayarDibuat'
import LayarLogin from './LayarLogin'
import LayarNota from './LayarNota'
import LayarRiwayat from './LayarRiwayat'
import LayarSetelan from './LayarSetelan'

/**
 * Kerangka rute app kasir.
 *
 * Router masuk begitu nota berhenti jadi layar sekali-lewat. Selama ia cuma
 * hidup beberapa detik sesudah konfirmasi, alamat memang ongkos tanpa manfaat.
 * Begitu nota harus bisa DIBUKA ULANG, "bisa dibuka kapan saja" dan "punya
 * alamat" jadi satu hal yang sama.
 *
 * Login sengaja TETAP bukan alamat: "sudah masuk atau belum" bukan tujuan yang
 * perlu dibagikan atau di-bookmark, dan menjadikannya rute berarti menambah
 * penjaga di setiap rute lain untuk pertanyaan yang jawabannya sama di
 * mana-mana.
 */
export default function App() {
  // Dibaca sekali saat mount: kasir yang me-refresh tab di tengah shift tak
  // perlu login ulang. Token yang ternyata sudah mati ketahuan pada permintaan
  // pertama, dan layarnyalah yang memulangkannya ke sini.
  const [masuk, setMasuk] = useState(() => bacaToken() !== null)

  const keluar = () => setMasuk(false)

  return (
    <BrowserRouter>
      {masuk ? (
        <Routes>
          <Route path="/" element={<LayarAntrean onKeluar={keluar} />} />
          {/* Nota memakai id pesanan, bukan nomor pesanan: nomor dibangkitkan
              acak dan diulang saat bentrok, sedangkan id yang dipegang server
              tunggal. Alamat harus menunjuk satu pesanan, bukan sekumpulan
              yang kebetulan bernomor sama. */}
          <Route path="/riwayat" element={<LayarRiwayat onKeluar={keluar} />} />
          {/* Punya alamat sendiri, bukan menumpang antrean: yang meracik minuman
              dan yang menerima uang sering bukan orang yang sama, dan begitu KDS
              ada, alamat inilah yang dipindahkan ke layar dapur. */}
          <Route path="/dibuat" element={<LayarDibuat onKeluar={keluar} />} />
          {/* Tak dijaga di sini: penjaganya `role:owner` di Ordering, dan
              kasir yang memaksa alamat ini melihat form kosong dengan pesan
              403 — bukan setelan outlet. Menambahkan penjaga kedua di layar
              berarti dua tempat memutuskan hal yang sama, dan yang di sini
              justru yang paling mudah dibohongi. */}
          <Route path="/setelan" element={<LayarSetelan onKeluar={keluar} />} />
          <Route path="/nota/:id" element={<LayarNota onKeluar={keluar} />} />
          {/* Alamat asing dikembalikan ke antrean, bukan dibiarkan jadi layar
              putih — kasir tak punya cara menebak apa yang salah. */}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      ) : (
        // Alamatnya sengaja tak diubah saat sesi berakhir: kasir yang tokennya
        // kedaluwarsa di layar nota kembali ke nota yang sama setelah masuk
        // lagi, bukan dilempar ke antrean dan disuruh mencarinya ulang.
        <LayarLogin onMasuk={() => setMasuk(true)} />
      )}
    </BrowserRouter>
  )
}
