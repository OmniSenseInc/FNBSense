<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Asal-usul permintaan di belakang gerbang.
 *
 * Bukan sekadar soal log yang rapi. Rate limiter di service ini (`orders`,
 * `claim`, dan throttle bawaan) mengunci embernya pada `$request->ip()`. Kalau
 * nilai itu sama untuk semua orang — dan di belakang Traefik memang begitu,
 * kecuali proxy-nya dipercaya — maka satu orang yang mengirim 20 pesanan per
 * menit menutup jalur pesan bagi SELURUH pelanggan kafe. Pagar berubah jadi
 * tuas penguncian.
 *
 * Dua kasus di bawah menjaga kedua sisi kesalahan sekaligus: tidak mempercayai
 * gerbang sama sekali (celah DoS), dan mempercayai siapa saja (celah pengelakan
 * limiter, sebab X-Forwarded-For cuma teks yang boleh diketik siapa pun).
 */
class TrustedProxyTest extends TestCase
{
    /** Alamat di dalam jaringan Docker — hanya tetangga container yang bisa memakainya. */
    private const ALAMAT_GERBANG = '172.18.0.5';

    /** Alamat publik sembarang; berlaku sebagai penyerang yang menembak langsung. */
    private const ALAMAT_LUAR = '198.51.100.9';

    private const ALAMAT_DIAKU = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        // Rute uji, bukan endpoint sungguhan: yang diperiksa milik middleware
        // global, jadi rute apa pun sama sahihnya — dan yang sederhana tak
        // menyeret basis data, Catalog tiruan, atau token ke dalam pemeriksaan.
        Route::get('/_asal-usul', fn (Request $request) => ['ip' => $request->ip()]);
    }

    public function test_alamat_pelanggan_dibaca_dari_gerbang(): void
    {
        $this->call('GET', '/_asal-usul', server: [
            'REMOTE_ADDR' => self::ALAMAT_GERBANG,
            'HTTP_X_FORWARDED_FOR' => self::ALAMAT_DIAKU,
        ])->assertOk()->assertJson(['ip' => self::ALAMAT_DIAKU]);
    }

    /**
     * Sisi sebaliknya, dan inilah yang menahan "perbaikan" berupa
     * `trustProxies(at: '*')`: dengan itu penyerang cukup melampirkan
     * X-Forwarded-For karangan pada tiap permintaan untuk mendapat ember baru
     * setiap kali — limiternya masih menyala, tapi tak lagi membatasi apa pun.
     */
    public function test_penembak_langsung_tak_bisa_mengarang_asal_usulnya(): void
    {
        $this->call('GET', '/_asal-usul', server: [
            'REMOTE_ADDR' => self::ALAMAT_LUAR,
            'HTTP_X_FORWARDED_FOR' => self::ALAMAT_DIAKU,
        ])->assertOk()->assertJson(['ip' => self::ALAMAT_LUAR]);
    }
}
