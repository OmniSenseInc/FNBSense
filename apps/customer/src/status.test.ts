import { describe, expect, it } from 'vitest'
import { pesananMasihAktif } from './status'

describe('pesananMasihAktif', () => {
  it('menunggu bayar masih aktif', () => {
    expect(pesananMasihAktif('pending', null)).toBe(true)
  })

  it('sudah bayar tapi belum siap masih aktif', () => {
    expect(pesananMasihAktif('paid', null)).toBe(true)
  })

  it('sudah siap diantar dianggap selesai', () => {
    expect(pesananMasihAktif('paid', '2026-01-02T15:00:00Z')).toBe(false)
  })

  it('dibatalkan dianggap selesai (dua ejaan)', () => {
    expect(pesananMasihAktif('cancelled', null)).toBe(false)
    expect(pesananMasihAktif('canceled', null)).toBe(false)
  })

  it('kedaluwarsa dianggap selesai', () => {
    expect(pesananMasihAktif('expired', null)).toBe(false)
  })

  it('status tak dikenal tetap dianggap aktif', () => {
    expect(pesananMasihAktif('status_baru', null)).toBe(true)
  })
})
