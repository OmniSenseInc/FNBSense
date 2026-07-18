<?php

namespace App\Services;

/**
 * Nomor order yang diucapkan customer & kasir ("order K7P2QX").
 *
 * Token ACAK, bukan sekuens berurutan: sekuens per-outlet butuh hitung-lalu-tulis
 * yang balapan (dua order barengan -> nomor sama). Acak menghindari race tanpa
 * kunci, dan tak membocorkan berapa banyak order sudah masuk hari itu. Keunikan
 * tetap dijamin DB (unique outlet_id+order_number); pemanggil me-retry saat bentrok.
 *
 * Alfabet sengaja TANPA 0/O/1/I/L: token ini diketik/diucapkan manusia di kasir,
 * jadi karakter yang gampang tertukar dibuang.
 */
class OrderNumberGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Panjang token. 31^6 ~ 8.8e8 kombinasi -> bentrok per outlet praktis nihil. */
    public const LENGTH = 6;

    public function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // random_int() = CSPRNG. Jangan diganti rand()/mt_rand().
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
