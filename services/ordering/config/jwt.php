<?php

/*
|--------------------------------------------------------------------------
| Override key JWT (FNBSense) — parsial, disengaja
|--------------------------------------------------------------------------
|
| File ini SENGAJA hanya berisi blok `keys`. Provider paket memanggil
| mergeConfigFrom(..., 'jwt') = array_merge(config vendor, config app):
| setelan lain (ttl, algo, blacklist, dst) tetap dari vendor, hanya sub-array
| `keys` yang diganti UTUH — karena itu ketiga subkunci wajib disebut lengkap.
|
| Tujuannya satu: path key boleh ditulis RELATIF terhadap root service di
| .env, lalu diabsolutkan di sini. Dengan begitu satu .env.example sama
| benarnya di Windows, Linux, container, dan CI — tak ada lagi path absolut
| yang ikut ter-commit ke repo publik.
|
| Prefix `file://` WAJIB dipertahankan: nilainya diteruskan apa adanya ke
| openssl_* lewat InMemory::plainText(), dan skema itulah yang membedakan
| "ini path berkas" dari "ini isi PEM". Nilai tanpa `file://` (mis. isi PEM
| yang di-inject langsung dari secret manager) sengaja dibiarkan utuh.
|
| Catatan operasional: `config:cache` membekukan hasil resolusi ini, jadi
| jangan pernah mengirim cache config lintas OS.
*/
$resolveKeyPath = static function (?string $value): ?string {
    if (! is_string($value) || ! str_starts_with($value, 'file://')) {
        return $value;
    }

    $path = substr($value, 7);

    // Absolut = "/..." (Unix) atau "C:\..." / "C:/..." (Windows).
    if (! preg_match('#^(?:/|[A-Za-z]:[\\\\/])#', $path)) {
        $path = base_path($path);
    }

    return 'file://'.str_replace('\\', '/', $path);
};

return [
    'keys' => [
        // Ordering hanya MEMVERIFIKASI token terbitan IAM, tak pernah menerbitkan.
        // Slot `private` sengaja diisi PUBLIC key yang sama: lcobucci menolak key
        // kosong ("Key cannot be empty") walau cuma verify, sementara public key
        // tak bisa menandatangani apa pun — slot terisi tanpa menambah risiko.
        'public' => $resolveKeyPath(env('JWT_PUBLIC_KEY')),
        'private' => $resolveKeyPath(env('JWT_PRIVATE_KEY')),
        'passphrase' => env('JWT_PASSPHRASE'),
    ],
];