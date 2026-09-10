<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // POST /api/orders publik. Cukup pagar per IP — satu ember per alamat.
        //
        // Dulu ada lapis kedua `per qr_token (10/menit)`. Itu ternyata SALAH
        // desain: mengunci per MEJA berarti satu meja rame (beberapa orang pesan
        // bareng) jebol ambang dan ditolak, padahal mereka tak bersalah. Lebih
        // parah, pesanan yang DIBATALKAN kasir tetap terhitung — jadi customer
        // yang salah-pesan lalu dibatalkan, terus mau pesan ulang, makin dekat ke
        // 429 tanpa salah apa pun. Meja bukan identitas "pelaku", jadi bukan
        // tempat yang benar untuk menahan spam.
        //
        // yang memagari satu pelaku (orang / skrip yang membanjiri) cukup `per IP`.
        // Ambang 30/menit: satu HP normal tak akan menyentuhnya (batal + pesan
        // ulang belasan kali pun masih aman), sementara satu alamat yang
        // menembaki 30 pesanan/menit jelas spam.
        RateLimiter::for('orders', function (Request $request) {
            return [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });

        // POST /api/orders/{id}/claim-paid, juga publik. Dibatasi per IP DAN per
        // pesanan: yang pertama menahan orang yang menembaki banyak id sekaligus,
        // yang kedua menahan ketukan berulang pada satu pesanan. Ambang per
        // pesanan boleh rendah — sesudah klaim pertama, ketukan berikutnya tak
        // mengubah apa pun dan cuma memakan lock baris.
        RateLimiter::for('claim', function (Request $request) {
            return [
                Limit::perMinute(20)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('order:'.(string) $request->route('id')),
            ];
        });
    }
}
