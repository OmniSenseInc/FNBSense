<?php

/*
|--------------------------------------------------------------------------
| CORS — menimpa bawaan framework
|--------------------------------------------------------------------------
|
| Tanpa berkas ini yang berlaku adalah config/cors.php milik laravel/framework,
| dan isinya `'allowed_origins' => ['*']` untuk `api/*`. Artinya situs mana pun
| boleh membaca balasan API ini dari browser pengunjungnya.
|
| Selama ini tak menyakiti karena semua lalu lintas satu origin: di
| pengembangan lewat proxy Vite, di produksi lewat Traefik dengan awalan path
| yang sama. CORS tak pernah ikut bermain, jadi izin terbukanya tak pernah
| dipakai — dan justru itu yang membuatnya mudah dilupakan sampai backend
| dipindah ke domain sendiri.
|
| Yang berubah di sini cuma DEFAULT-nya: dari "semua boleh" jadi "tak ada yang
| boleh". Daftarnya diisi lewat CORS_ALLOWED_ORIGINS (dipisah koma) kalau suatu
| saat memang ada origin lain yang sah — bukan dengan mengembalikan '*'.
|
| Kosong TIDAK memutus apa pun yang berjalan sekarang: permintaan satu origin
| tak pernah melewati pemeriksaan CORS sama sekali.
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Token dikirim sebagai header Authorization oleh JavaScript, bukan cookie.
    // Menyalakan ini akan membuat browser ikut mengirim cookie lintas-origin —
    // hal yang tak dibutuhkan siapa pun di sistem ini, dan yang membuat '*'
    // pada allowed_origins ditolak spesifikasi justru karena berbahaya.
    'supports_credentials' => false,
];
