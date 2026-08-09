<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWT;
use Tests\TestCase;

/**
 * Ganti sandi sendiri, dan reset sandi kasir oleh owner.
 *
 * Sebelum ini sandi HANYA pernah ditetapkan saat register dan saat owner
 * membuat kasir. Akibatnya dua-duanya buntu: kasir yang lupa sandinya tak
 * punya jalan keluar sama sekali (email unik membuat akun lamanya nyangkut),
 * dan sandi yang diketikkan owner tak pernah bisa dipensiunkan pemakainya.
 *
 * Pembagian yang dipilih, dan alasannya:
 *
 *  - Ganti sendiri WAJIB menyebut sandi lama. Tanpa itu, token yang bocor
 *    (dicuri dari perangkat yang ditinggal terbuka) langsung jadi pengambilan
 *    alih akun permanen — pencurinya mengganti sandi, pemiliknya terkunci.
 *  - Reset oleh owner TIDAK menyebut sandi lama; dia memang tak tahu, dan itu
 *    seluruh gunanya.
 *  - Tapi owner TIDAK boleh mereset sandinya SENDIRI lewat jalur kedua. Kalau
 *    boleh, syarat "sebut sandi lama" di atas jadi sia-sia: pemegang token
 *    owner cukup menembak akunnya sendiri lewat pintu samping.
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private const SANDI_LAMA = 'Password123';

    private const SANDI_BARU = 'Rahasia456';

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function registerOwner(array $override = []): array
    {
        return $this->postJson('/api/auth/register', array_merge([
            'business_name' => 'Kopi Senja',
            'name' => 'Vincent',
            'email' => 'owner@kopisenja.test',
            'password' => self::SANDI_LAMA,
            'password_confirmation' => self::SANDI_LAMA,
        ], $override))->assertCreated()->json();
    }

    /** Lihat StaffTest::withTokenFresh — guard DAN objek JWT sama-sama menyimpan state. */
    private function withTokenFresh(string $token): static
    {
        $this->bersih();

        return $this->withToken($token);
    }

    private function bersih(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->make(JWT::class)->unsetToken();

        return $this;
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed> staf yang baru dibuat
     */
    private function buatKasir(string $tokenOwner, array $override = []): array
    {
        return $this->withTokenFresh($tokenOwner)
            ->postJson('/api/staff', array_merge([
                'name' => 'Kasir Satu',
                'email' => 'kasir1@kopisenja.test',
                'password' => 'Kasir12345',
            ], $override))
            ->assertCreated()
            ->json();
    }

    private function login(string $email, string $sandi)
    {
        return $this->bersih()->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $sandi,
        ]);
    }

    // ---------- GANTI SANDI SENDIRI ----------

    public function test_pemilik_token_bisa_mengganti_sandinya_sendiri(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/auth/password', [
                'current_password' => self::SANDI_LAMA,
                'password' => self::SANDI_BARU,
                'password_confirmation' => self::SANDI_BARU,
            ])
            ->assertOk();

        $this->login('owner@kopisenja.test', self::SANDI_BARU)->assertOk();
    }

    /** Sandi lama harus benar-benar berhenti bekerja, bukan sekadar sandi baru ikut diterima. */
    public function test_sandi_lama_tak_bisa_dipakai_lagi(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/auth/password', [
                'current_password' => self::SANDI_LAMA,
                'password' => self::SANDI_BARU,
                'password_confirmation' => self::SANDI_BARU,
            ])
            ->assertOk();

        $this->login('owner@kopisenja.test', self::SANDI_LAMA)->assertUnauthorized();
    }

    public function test_sandi_lama_yang_salah_ditolak(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/auth/password', [
                'current_password' => 'BukanSandinya9',
                'password' => self::SANDI_BARU,
                'password_confirmation' => self::SANDI_BARU,
            ])
            ->assertStatus(422);

        // Dan sandi lamanya tetap berlaku — penolakan tak boleh setengah jadi.
        $this->login('owner@kopisenja.test', self::SANDI_LAMA)->assertOk();
    }

    public function test_sandi_baru_lemah_ditolak(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/auth/password', [
                'current_password' => self::SANDI_LAMA,
                'password' => 'lemahsekali',
                'password_confirmation' => 'lemahsekali',
            ])
            ->assertStatus(422);
    }

    public function test_konfirmasi_yang_tak_cocok_ditolak(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/auth/password', [
                'current_password' => self::SANDI_LAMA,
                'password' => self::SANDI_BARU,
                'password_confirmation' => 'Rahasia999',
            ])
            ->assertStatus(422);
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->registerOwner();

        $this->bersih()->postJson('/api/auth/password', [
            'current_password' => self::SANDI_LAMA,
            'password' => self::SANDI_BARU,
            'password_confirmation' => self::SANDI_BARU,
        ])->assertUnauthorized();
    }

    /** Kasir juga berhak — ini bukan wewenang owner, ini kebersihan akun sendiri. */
    public function test_kasir_juga_bisa_mengganti_sandinya(): void
    {
        $owner = $this->registerOwner();
        $this->buatKasir($owner['access_token']);

        $masuk = $this->login('kasir1@kopisenja.test', 'Kasir12345')->assertOk()->json();

        $this->withTokenFresh($masuk['access_token'])
            ->postJson('/api/auth/password', [
                'current_password' => 'Kasir12345',
                'password' => self::SANDI_BARU,
                'password_confirmation' => self::SANDI_BARU,
            ])
            ->assertOk();

        $this->login('kasir1@kopisenja.test', self::SANDI_BARU)->assertOk();
    }

    // ---------- RESET OLEH OWNER ----------

    public function test_owner_bisa_mereset_sandi_kasir_yang_lupa(): void
    {
        $owner = $this->registerOwner();
        $kasir = $this->buatKasir($owner['access_token']);

        $this->withTokenFresh($owner['access_token'])
            ->putJson('/api/staff/'.$kasir['id'], ['password' => self::SANDI_BARU])
            ->assertOk();

        $this->login('kasir1@kopisenja.test', self::SANDI_BARU)->assertOk();
        $this->login('kasir1@kopisenja.test', 'Kasir12345')->assertUnauthorized();
    }

    public function test_reset_oleh_owner_tetap_tunduk_aturan_kekuatan_sandi(): void
    {
        $owner = $this->registerOwner();
        $kasir = $this->buatKasir($owner['access_token']);

        $this->withTokenFresh($owner['access_token'])
            ->putJson('/api/staff/'.$kasir['id'], ['password' => 'lemahsekali'])
            ->assertStatus(422);
    }

    /**
     * Pintu samping yang harus tertutup: kalau owner boleh mereset sandinya
     * sendiri di sini, syarat "sebut sandi lama" pada ganti-sandi jadi sia-sia.
     */
    public function test_owner_tak_bisa_mereset_sandinya_sendiri_lewat_jalur_ini(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->putJson('/api/staff/'.$owner['user']['id'], ['password' => self::SANDI_BARU])
            ->assertStatus(422);

        // Sandinya harus benar-benar tak berubah.
        $this->login('owner@kopisenja.test', self::SANDI_LAMA)->assertOk();
    }

    public function test_owner_tak_bisa_mereset_sandi_staf_tenant_lain(): void
    {
        $ownerA = $this->registerOwner();
        $ownerB = $this->registerOwner([
            'business_name' => 'Kopi Pagi',
            'name' => 'Bayu',
            'email' => 'owner@kopipagi.test',
        ]);
        $kasirB = $this->buatKasir($ownerB['access_token'], ['email' => 'kasir@kopipagi.test']);

        $this->withTokenFresh($ownerA['access_token'])
            ->putJson('/api/staff/'.$kasirB['id'], ['password' => self::SANDI_BARU])
            ->assertNotFound();

        $this->login('kasir@kopipagi.test', 'Kasir12345')->assertOk();
    }

    /** Nama & status tetap bisa diubah bersamaan — medan sandi tak boleh mengunci yang lain. */
    public function test_ubah_nama_tanpa_sandi_tetap_jalan(): void
    {
        $owner = $this->registerOwner();
        $kasir = $this->buatKasir($owner['access_token']);

        $this->withTokenFresh($owner['access_token'])
            ->putJson('/api/staff/'.$kasir['id'], ['name' => 'Kasir Berganti Nama'])
            ->assertOk()
            ->assertJsonPath('name', 'Kasir Berganti Nama');

        $this->login('kasir1@kopisenja.test', 'Kasir12345')->assertOk();
    }
}
