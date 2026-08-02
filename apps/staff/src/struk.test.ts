import { describe, expect, it } from 'vitest'
import type { Pesanan } from './api'
import { barisStruk, bungkus, keAscii, keBytes, kolom, type IdentitasOutlet } from './struk'

const OUTLET: IdentitasOutlet = {
  nama: 'Kopi Senja',
  alamat: 'Jl. Contoh No. 123',
  telepon: '0812-3456-7890',
}

function pesanan(ubah: Partial<Pesanan> = {}): Pesanan {
  return {
    id: 'ord-1',
    order_number: 'A-001',
    customer_name: 'Vincent',
    grand_total: 81_200,
    subtotal: 70_000,
    layanan: 3_500,
    pajak: 7_700,
    caraBayar: 'qris_static',
    niatBayar: null,
    meja: 'Meja 4',
    tipe: 'dine_in',
    waktuBayar: '2026-08-02T14:23:00+07:00',
    klaimBayar: null,
    siapPada: null,
    created_at: '2026-08-02T14:20:00+07:00',
    expires_at: null,
    items: [
      { nama: 'Kopi Susu', qty: 2, hargaSatuan: 22_500, total: 45_000, note: 'tanpa gula' },
      { nama: 'Croissant', qty: 1, hargaSatuan: 25_000, total: 25_000, note: '' },
    ],
    ...ubah,
  }
}

/** Semua teks struk digabung — untuk memeriksa "ada/tidak ada". */
function teksPenuh(baris: { teks: string }[]): string {
  return baris.map((b) => b.teks).join('\n')
}

describe('barisStruk', () => {
  it('mencetak identitas kafe di kepala', () => {
    const isi = teksPenuh(barisStruk(pesanan(), OUTLET, 32))

    expect(isi).toContain('Kopi Senja')
    expect(isi).toContain('Jl. Contoh No. 123')
    expect(isi).toContain('0812-3456-7890')
  })

  it('outlet tanpa identitas tidak meninggalkan baris kosong', () => {
    // Baris kosong di kepala struk bukan cuma jelek — ia memakan kertas pada
    // setiap transaksi, seumur hidup kafe itu.
    const baris = barisStruk(pesanan(), { nama: null, alamat: null, telepon: null }, 32)

    expect(baris.every((b) => b.teks.trim() !== '')).toBe(true)
    expect(baris[0].teks).toBe('='.repeat(32))
  })

  it('tidak ada baris yang melebihi lebar kertas', () => {
    for (const lebar of [32, 48]) {
      for (const baris of barisStruk(pesanan(), OUTLET, lebar)) {
        // Baris 'judul' dicetak ukuran dobel, jadi jatah karakternya separuh.
        const batas = baris.gaya === 'judul' ? Math.floor(lebar / 2) : lebar

        expect(
          baris.teks.length,
          `"${baris.teks}" (${baris.gaya}) di lebar ${lebar}`,
        ).toBeLessThanOrEqual(batas)
      }
    }
  })

  it('setiap karakter bisa dicetak printer termal', () => {
    // Penjaga terpenting di berkas ini. Printer termal memakai code page satu
    // byte; satu karakter UTF-8 yang lolos keluar sebagai huruf acak — dan yang
    // paling mungkin lolos justru `×` yang kita pakai di seluruh layar.
    const baris = barisStruk(
      pesanan({
        customer_name: 'José',
        items: [
          {
            nama: 'Kopi × 2 🙏',
            qty: 1,
            hargaSatuan: 1_000,
            total: 1_000,
            note: 'pakai es — banyak',
          },
        ],
        subtotal: 1_000,
        layanan: 0,
        pajak: 0,
        grand_total: 1_000,
      }),
      // Telepon sengaja memakai tanda hubung panjang: medan ini TIDAK dibungkus,
      // jadi ia satu-satunya yang membuktikan jaring terakhir di `tulis()`
      // benar-benar bekerja. Tanpa ini, mencabut jaring itu tak membuat satu
      // pun test merah — sudah dibuktikan lewat mutasi.
      { nama: 'Café Señor', alamat: null, telepon: '0812–3456–7890' },
      32,
    )

    for (const b of baris) {
      for (const huruf of b.teks) {
        expect(huruf.charCodeAt(0), `karakter "${huruf}" di "${b.teks}"`).toBeLessThan(128)
      }
    }
  })

  it('menolak mencetak saat angka pesanan tak terbaca', () => {
    // Mapper kita sengaja menjadikan angka rusak jadi NaN, bukan 0. Struk
    // bertulis "Rp 0" adalah bukti tertulis yang salah di tangan pelanggan.
    expect(() => barisStruk(pesanan({ grand_total: NaN }), OUTLET, 32)).toThrow(/tak terbaca/)
  })

  it('menolak juga saat satu baris item yang rusak', () => {
    const rusak = pesanan()
    rusak.items[0].total = NaN

    expect(() => barisStruk(rusak, OUTLET, 32)).toThrow(/tak terbaca/)
  })

  it('mencetak angka SERVER, bukan hasil hitungannya sendiri', () => {
    // Diberi subtotal yang sengaja tak cocok dengan jumlah itemnya. Struk yang
    // menghitung sendiri akan "memperbaiki" ini diam-diam — dan sejak saat itu
    // angka di kertas berbeda dari angka di laporan.
    const isi = teksPenuh(barisStruk(pesanan({ subtotal: 99_999 }), OUTLET, 32))

    expect(isi).toContain('Rp 99.999')
    expect(isi).not.toContain('Rp 70.000')
  })

  it('catatan item ikut tercetak dan menjorok', () => {
    // Selama layar dapur belum ada, inilah satu-satunya jalan "tanpa gula"
    // sampai ke tangan yang meracik.
    const baris = barisStruk(pesanan(), OUTLET, 32)

    expect(baris.some((b) => b.teks === '- tanpa gula')).toBe(true)
  })

  it('pungutan wajib tetap dicetak walau nol', () => {
    // Sengaja DIBALIK dari versi pertama berkas ini. Menghemat dua baris
    // kertas terdengar masuk akal sampai kamu sadar layarnya tetap menampilkan
    // "Pajak Rp 0": pelanggan yang membandingkan struk dengan layar kasir
    // melihat dua dokumen berbeda untuk satu transaksi. Yang boleh hilang saat
    // nol cuma yang opsional (promo), bukan pungutan wajib.
    const isi = teksPenuh(barisStruk(pesanan({ layanan: 0, pajak: 0 }), OUTLET, 32))

    expect(isi).toContain('Layanan')
    expect(isi).toContain('Pajak')
  })

  it('total memakai gaya tebal, dan hanya total', () => {
    const tebal = barisStruk(pesanan(), OUTLET, 32).filter((b) => b.gaya === 'tebal')

    expect(tebal).toHaveLength(1)
    expect(tebal[0].teks).toContain('TOTAL')
  })
})

describe('kolom', () => {
  it('menempelkan kanan ke tepi kanan', () => {
    expect(kolom('Total', 'Rp 100', 20)).toBe('Total         Rp 100')
  })

  it('memotong KIRI saat tak muat, tak pernah kanan', () => {
    // Yang kanan selalu angka uang. Angka yang terpotong adalah angka salah,
    // dan struk salah lebih buruk daripada nama menu yang tersingkat.
    const hasil = kolom('Nama menu yang sangat panjang sekali', 'Rp 100', 20)

    expect(hasil).toHaveLength(20)
    expect(hasil.endsWith('Rp 100')).toBe(true)
  })
})

describe('bungkus', () => {
  it('memecah di spasi, bukan di tengah kata', () => {
    expect(bungkus('Kopi Senja Kedai', 12)).toEqual(['Kopi Senja', 'Kedai'])
  })

  it('memenggal kata yang lebih panjang dari kertas', () => {
    expect(bungkus('AAAAAAAAAA', 4)).toEqual(['AAAA', 'AAAA', 'AA'])
  })
})

describe('keAscii', () => {
  it('mengganti tanda kali yang dipakai di seluruh layar', () => {
    expect(keAscii('2 × Kopi')).toBe('2 x Kopi')
  })

  it('membuang yang tak dikenal alih-alih menaruh tanda tanya', () => {
    expect(keAscii('Kopi 🙏 Susu')).toBe('Kopi  Susu')
  })
})

describe('keBytes', () => {
  it('diawali reset printer dan diakhiri potong kertas', () => {
    const bytes = keBytes(barisStruk(pesanan(), OUTLET, 32))

    expect([bytes[0], bytes[1]]).toEqual([0x1b, 0x40])
    expect([...bytes.slice(-3)]).toEqual([0x1d, 0x56, 0x00])
  })

  it('memberi jarak sebelum potong', () => {
    // Pisau berada beberapa milimeter di atas kepala cetak. Tanpa jarak ini
    // bagian bawah struk — tempat TOTAL berada — terpotong separuh.
    const bytes = keBytes([{ teks: 'x', gaya: 'normal' }])

    expect([...bytes.slice(-6, -3)]).toEqual([0x0a, 0x0a, 0x0a])
  })
})
