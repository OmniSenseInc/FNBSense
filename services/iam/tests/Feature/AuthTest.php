<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payload register default; bisa dioverride per-test.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function registerPayload(array $override = []): array
    {
        return array_merge([
            'business_name' => 'Kopi Senja',
            'name' => 'Vincent',
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $override);
    }

    /**
     * Daftarkan owner baru dan kembalikan access token-nya.
     */
    private function tokenForNewOwner(): string
    {
        return $this->postJson('/api/auth/register', $this->registerPayload())
            ->json('access_token');
    }

    // ---------- REGISTER ----------

    public function test_register_membuat_tenant_dan_owner_serta_mengembalikan_token(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registerPayload());

        $response->assertCreated()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'user' => ['id', 'name', 'email', 'role', 'tenant_id', 'outlet_id'],
            ])
            ->assertJsonPath('user.role', 'owner');

        $this->assertDatabaseHas('tenants', ['name' => 'Kopi Senja', 'slug' => 'kopi-senja']);
        $this->assertDatabaseHas('users', ['email' => 'owner@kopisenja.test', 'role' => 'owner']);

        $user = User::firstOrFail();
        $this->assertNotNull($user->tenant_id);
        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertTrue($user->is_active);

        // Owner WAJIB langsung terikat outlet default — ini yang bikin service
        // hilir (Ordering) bisa dipakai. outlet_id null = 403 di semua endpoint
        // ber-outlet, jadi assertion ini menjaga kontrak lintas-service.
        $this->assertNotNull($user->outlet_id, 'Owner baru harus punya outlet_id, bukan null.');
        $this->assertDatabaseHas('outlets', [
            'id' => $user->outlet_id,
            'tenant_id' => $user->tenant_id,
        ]);
        $response->assertJsonPath('user.outlet_id', $user->outlet_id);
    }

    public function test_register_mengabaikan_role_dan_tenant_id_dari_input(): void
    {
        // Percobaan privilege escalation: user menyuntik role & tenant_id.
        $response = $this->postJson('/api/auth/register', $this->registerPayload([
            'role' => 'cashier',
            'tenant_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]));

        $response->assertCreated();

        $user = User::firstOrFail();
        $this->assertSame(UserRole::Owner, $user->role, 'Role harus selalu owner, bukan dari input.');
        $this->assertNotSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $user->tenant_id, 'tenant_id tidak boleh dari input.');
    }

    public function test_register_menolak_email_duplikat(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/auth/register', $this->registerPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_register_menolak_password_tanpa_konfirmasi_yang_cocok(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload([
            'password_confirmation' => 'beda-sendiri',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** Policy password: min 8 + huruf besar-kecil + angka. Yang lemah ditolak 422. */
    public function test_register_menolak_password_lemah(): void
    {
        // tanpa huruf besar & tanpa angka
        $this->postJson('/api/auth/register', $this->registerPayload([
            'password' => 'lemahsekali',
            'password_confirmation' => 'lemahsekali',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        // ada huruf besar-kecil tapi tanpa angka
        $this->postJson('/api/auth/register', $this->registerPayload([
            'password' => 'TanpaAngka',
            'password_confirmation' => 'TanpaAngka',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ---------- LOGIN ----------

    public function test_login_berhasil_dengan_kredensial_benar(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
        ])->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user']);
    }

    public function test_login_gagal_dengan_password_salah(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'password-salah',
        ])->assertUnauthorized();
    }

    public function test_login_ditolak_untuk_user_nonaktif(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();
        User::where('email', 'owner@kopisenja.test')->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
        ])->assertUnauthorized();
    }

    public function test_login_ditolak_jika_tenant_atau_outlet_tidak_aktif(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();
        $user = User::firstOrFail();

        Tenant::whereKey($user->tenant_id)->update(['is_active' => false]);
        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
        ])->assertUnauthorized();

        Tenant::whereKey($user->tenant_id)->update(['is_active' => true]);
        Outlet::whereKey($user->outlet_id)->update(['is_active' => false]);
        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
        ])->assertUnauthorized();
    }

    public function test_login_ditolak_jika_outlet_bukan_milik_tenant_user(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();
        $user = User::firstOrFail();
        $otherTenant = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
        ]);
        $otherOutlet = Outlet::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Outlet Tenant B',
            'is_active' => true,
        ]);
        $user->update(['outlet_id' => $otherOutlet->id]);

        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
        ])->assertUnauthorized();
    }

    // ---------- ME / LOGOUT / REFRESH ----------

    public function test_me_butuh_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_mengembalikan_user_tanpa_password(): void
    {
        $token = $this->tokenForNewOwner();

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertOk()->assertJsonPath('email', 'owner@kopisenja.test');
        $this->assertArrayNotHasKey('password', $response->json());
    }

    public function test_tenant_nonaktif_tidak_bisa_melihat_profil_atau_refresh_token(): void
    {
        $token = $this->tokenForNewOwner();
        $user = User::firstOrFail();
        Tenant::whereKey($user->tenant_id)->update(['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertForbidden();
    }

    /**
     * Akun yang DIHAPUS setelah token terbit: tokennya masih sah, tapi `me`
     * harus membedakan ini dari token rusak — 403, bukan 401. Inilah yang
     * dipakai app kasir/owner untuk memaksa logout tanpa menunggu token
     * kedaluwarsa (sampai 15 menit).
     */
    public function test_me_akun_dihapus_dibalas_403_bukan_401(): void
    {
        $token = $this->tokenForNewOwner();
        $user = User::firstOrFail();

        $user->delete(); // soft delete — simulasi kasir yang dihapus owner

        // Satu test = satu instance app, dan guard JWT adalah singleton yang
        // meng-cache user dari request register (via login()). Tanpa ini, `me`
        // memakai user cache yang basi — padahal di produksi tiap request punya
        // guard sendiri dan cache ini tak pernah ada.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertForbidden();
    }

    public function test_logout_mematikan_token(): void
    {
        $token = $this->tokenForNewOwner();

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Token yang sama tidak boleh bisa dipakai lagi setelah logout.
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    /**
     * Refresh harus MENYEGARKAN klaim dari DB, bukan menyalin dari token lama.
     *
     * Catatan koreksi (2026-07-26): semula kusangka klaim HILANG saat refresh
     * dan menulis test yang memeriksa "klaim ada". Mutasi membuktikan sangkaan
     * itu keliru — klaim memang ikut terbawa, jadi test lama lolos baik dengan
     * maupun tanpa perbaikan. Masalah sebenarnya bukan hilang, tapi BASI:
     * klaim disalin dari token lama, sehingga staff yang baru diturunkan
     * haknya tetap membawa peran lamanya selama refresh window penuh (14 hari)
     * di keenam service hilir. Test ini mengunci kesegarannya, bukan
     * keberadaannya.
     */
    public function test_refresh_menyegarkan_klaim_dari_database(): void
    {
        $daftar = $this->postJson('/api/auth/register', $this->registerPayload())
            ->assertCreated()
            ->json();

        // Turunkan langsung di DB: API sengaja melarang owner mengubah perannya sendiri.
        User::whereKey($daftar['user']['id'])->update(['role' => UserRole::Cashier->value]);

        $baru = $this->withToken($daftar['access_token'])
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->json('access_token');

        // Dekode manual: memakai paket berarti menyentuh instance yang state-nya
        // hidup di proses test yang sama, dan itu sumber hijau-palsu.
        [, $payloadB64] = explode('.', $baru);
        $klaim = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

        $this->assertSame(
            'cashier',
            $klaim['role'] ?? null,
            'Klaim harus mengikuti DB terkini, bukan disalin dari token lama.'
        );
        $this->assertSame($daftar['user']['tenant_id'], $klaim['tenant_id'] ?? null);
        $this->assertSame($daftar['user']['outlet_id'], $klaim['outlet_id'] ?? null);
    }
    public function test_refresh_menghasilkan_token_baru(): void
    {
        $token = $this->tokenForNewOwner();

        $response = $this->withToken($token)->postJson('/api/auth/refresh');

        $response->assertOk()->assertJsonStructure(['access_token']);
        $this->assertNotSame($token, $response->json('access_token'));
    }

    /** Inti Opsi A: token yang BARU SAJA expired (lewat TTL 15m) masih bisa
     *  ditukar selama dalam refresh_ttl (2 minggu). */
    public function test_refresh_token_expired_dalam_window_tetap_berhasil(): void
    {
        $token = $this->tokenForNewOwner();

        // Maju 30 menit: token (TTL 15m) sudah expired, tapi jauh di dalam refresh_ttl.
        $this->travel(30)->minutes();

        $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['access_token']);
    }

    /** Di luar refresh_ttl (14 hari) token benar-benar mati — tak bisa di-refresh. */
    public function test_refresh_token_di_luar_window_ditolak(): void
    {
        $token = $this->tokenForNewOwner();

        $this->travel(15)->days(); // > refresh_ttl 20160 menit (14 hari)

        $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertUnauthorized();
    }

    public function test_register_ditutup_saat_gerbang_nonaktif(): void
    {
        config(['auth.register_open' => false]);

        $this->postJson('/api/auth/register', [
            'business_name' => 'Kafe Gelap',
            'name' => 'Orang',
            'email' => 'gelap@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertForbidden()->assertJson(['message' => 'Pendaftaran mandiri sedang ditutup. Hubungi pengelola untuk membuat akun.']);

        // Tidak ada tenant/bisnis apapun yang lahir dari percobaan itu.
        $this->assertEquals(0, \App\Models\Tenant::count());
    }

    public function test_login_gagal_tercatat_di_audit_keamanan(): void
    {
        config(['auth.register_open' => true]);
        $this->postJson('/api/auth/register', [
            'business_name' => 'Kafe Audit',
            'name' => 'Audit',
            'email' => 'audit@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->postJson('/api/auth/login', ['email' => 'audit@example.test', 'password' => 'SALAH!!!'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('security_events', [
            'type' => 'login_failed',
            'email' => 'audit@example.test',
        ]);
    }
}
