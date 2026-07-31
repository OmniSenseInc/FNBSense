import { describe, expect, it } from 'vitest'
import { labelMeja, sisaMenit } from './format'

describe('labelMeja', () => {
  it('memakai nama meja yang ditulis owner apa adanya', () => {
    expect(labelMeja('Meja 4', 'dine_in')).toBe('Meja 4')
  })

  it('pesanan bawa pulang memang tak punya meja', () => {
    expect(labelMeja(null, 'takeaway')).toBe('Bawa pulang')
  })

  it('dine-in tanpa meja tidak dikarang jadi "Bawa pulang"', () => {
    // Mejanya dihapus setelah pesanan dibuat. Menulis "Bawa pulang" di sini
    // menyuruh kasir mencari orang yang tak pernah antre di depannya.
    expect(labelMeja(null, 'dine_in')).toBeNull()
  })

  it('tipe yang tak dikenal tidak menghasilkan penanda apa pun', () => {
    expect(labelMeja(null, null)).toBeNull()
  })
})

/**
 * Semua tanggal ditulis dengan offset +07:00 eksplisit, dan "sekarang" disuntik
 * sebagai angka — jadi hasilnya tak pernah bergantung pada zona waktu mesin
 * yang menjalankan test.
 */
describe('sisaMenit', () => {
  const sekarang = Date.parse('2026-07-31T14:00:00+07:00')

  it('menghitung sisa menit sampai tenggat', () => {
    expect(sisaMenit('2026-07-31T14:07:00+07:00', sekarang)).toBe(7)
  })

  it('membulatkan KE ATAS, jadi sisa waktu tak tampil habis lebih cepat dari kenyataan', () => {
    // Tersisa 30 detik. Pembulatan ke bawah menulis "0" -> kasir menyimpulkan
    // pesanan sudah lewat batas padahal server masih menerimanya dengan normal.
    expect(sisaMenit('2026-07-31T14:00:30+07:00', sekarang)).toBe(1)
  })

  it('tepat di detik tenggat sudah dihitung lewat', () => {
    expect(sisaMenit('2026-07-31T14:00:00+07:00', sekarang)).toBe(0)
  })

  it('tenggat yang terlewat bernilai negatif, tidak dijepit ke nol', () => {
    // Dibiarkan negatif supaya "belum lewat", "pas lewat", dan "tak punya
    // tenggat" tetap tiga keadaan yang berbeda di layar.
    expect(sisaMenit('2026-07-31T13:50:00+07:00', sekarang)).toBe(-10)
  })

  it.each([
    ['tak ada', null],
    ['kosong', ''],
    ['bukan tanggal', 'segera'],
  ])('mengembalikan null untuk tenggat %s, bukan angka', (_nama, nilai) => {
    // NaN atau 0 di sini akan dirender sebagai "lewat batas" — kasir diberi
    // kesimpulan yang dikarang dari data yang sebenarnya tak terbaca.
    expect(sisaMenit(nilai, sekarang)).toBeNull()
  })
})
