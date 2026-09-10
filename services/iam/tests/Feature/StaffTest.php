<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\JWT;
use Tests\TestCase;

/**
 * F-iam-b — manajemen staff (owner membuat & mengelola kasir).
 *
 * Batasan yang sengaja dipilih (2026-07-26): kasir SELALU lahir di outlet owner.
 * Body tidak boleh menentukan outlet — selama tenant cuma punya satu outlet,
 * parameter itu murni permukaan serang tanpa manfaat. Reassign outlet menyusul
 * kalau outlet kedua benar-benar ada.
 */
class StaffTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Daftarkan owner baru; kembalikan respons register (access_token + user).
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function registerOwner(array $override = []): array
    {
        return $this->postJson('/api/auth/register', array_merge([
            'business_name' => 'Kopi Senja',
            'name' => 'Vincent',
            'email' => 'owner@kopisenja.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $override))->assertCreated()->json();
    }

    /**
     * Owner tenant kedua — dipakai untuk membuktikan isolasi antar-tenant.
     *
     * @return array<string, mixed>
     */
    private function registerOwnerB(): array
    {
        return $this->registerOwner([
            'business_name' => 'Kopi Pagi',
            'name' => 'Bayu',
            'email' => 'owner@kopipagi.test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function staffPayload(array $override = []): array
    {
        return array_merge([
            'name' => 'Kasir Satu',
            'email' => 'kasir1@kopisenja.test',
            'password' => 'Kasir12345',
        ], $override);
    }

    /**
     * Kirim request ber-token dengan state auth yang BERSIH.
     *
     * Aplikasi tidak dibangun ulang antar-request dalam satu test, jadi ada DUA
     * lapis state yang menempel dan harus dibuang manual:
     *
     *  1. Guard "api" menyimpan user hasil resolve di instance guard.
     *  2. Objek JWT singleton menyimpan token terakhir; `JWT::getToken()` hanya
     *     mengurai ulang dari request kalau `$this->token === null`.
     *
     * JEBAKAN: paket ini punya DUA binding berbeda. Guard dibangun dengan
     * `$app['tymon.jwt']` (kelas JWT), sedangkan facade `JWTAuth` menunjuk
     * `'tymon.jwt.auth'` (kelas JWTAuth) — objek lain. Membersihkan lewat
     * facade tidak berpengaruh sama sekali pada token yang dibaca guard.
     *
     * Tanpa keduanya, request berikutnya memakai user/token request SEBELUMNYA
     * alih-alih pemilik token yang dikirim — dan test isolasi tenant di bawah
     * lolos/gagal karena alasan yang salah. Di produksi ini tak terjadi (tiap
     * request = proses & aplikasi sendiri), jadi murni pagar harness.
     */
    private function withTokenFresh(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->make(JWT::class)->unsetToken();

        return $this->withToken($token);
    }

    // ---------- CREATE ----------

    public function test_owner_bisa_membuat_kasir(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated()
            ->assertJsonStructure(['id', 'name', 'email', 'role', 'outlet_id', 'is_active'])
            ->assertJsonPath('role', 'cashier');

        $kasir = User::where('email', 'kasir1@kopisenja.test')->firstOrFail();
        $this->assertSame(UserRole::Cashier, $kasir->role);
        $this->assertSame($owner['user']['tenant_id'], $kasir->tenant_id);
        $this->assertSame(
            $owner['user']['outlet_id'],
            $kasir->outlet_id,
            'Kasir harus mewarisi outlet owner — belum ada outlet kedua.'
        );
        $this->assertTrue($kasir->is_active);
    }

    /**
     * Privilege escalation: body minta role owner DITOLAK. Owner cuma satu per
     * tenant (pemegang akun) dan tak bisa dilahirkan lewat jalur staff.
     */
    public function test_role_owner_ditolak_saat_membuat_staff(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload(['role' => 'owner']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    /** Role tak dikenal ditolak — enum cuma kenal owner, manager, cashier. */
    public function test_role_tak_dikenal_ditolak(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload(['role' => 'admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    /** Owner bisa melahirkan manager (role dipilih eksplisit). */
    public function test_owner_bisa_membuat_manager(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload(['role' => 'manager']))
            ->assertCreated()
            ->assertJsonPath('role', 'manager');

        $manager = User::where('email', 'kasir1@kopisenja.test')->firstOrFail();
        $this->assertSame(UserRole::Manager, $manager->role);
    }

    /** Promosi/demosi: owner bisa mengubah role kasir menjadi manager. */
    public function test_owner_bisa_promosi_kasir_jadi_manager(): void
    {
        $owner = $this->registerOwner();
        $kasirId = $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated()
            ->json('id');

        $this->withTokenFresh($owner['access_token'])
            ->putJson("/api/staff/{$kasirId}", ['role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('role', 'manager');
    }

    /** Anti self-demotion: owner tak bisa mengubah role dirinya sendiri. */
    public function test_owner_tak_bisa_mengubah_role_dirinya_sendiri(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->putJson("/api/staff/{$owner['user']['id']}", ['role' => 'manager'])
            ->assertStatus(422);
    }

    /** Scoping struktural: tenant & outlet datang dari token, body diabaikan. */
    public function test_tenant_dan_outlet_dari_body_diabaikan(): void
    {
        $ownerA = $this->registerOwner();
        $ownerB = $this->registerOwnerB();

        $this->withTokenFresh($ownerA['access_token'])
            ->postJson('/api/staff', $this->staffPayload([
                'tenant_id' => $ownerB['user']['tenant_id'],
                'outlet_id' => $ownerB['user']['outlet_id'],
            ]))
            ->assertCreated();

        $kasir = User::where('email', 'kasir1@kopisenja.test')->firstOrFail();
        $this->assertSame($ownerA['user']['tenant_id'], $kasir->tenant_id, 'tenant_id tidak boleh dari body.');
        $this->assertSame($ownerA['user']['outlet_id'], $kasir->outlet_id, 'outlet_id tidak boleh dari body.');
    }

    /**
     * INTI slice ini: kasir hasil endpoint benar-benar bisa login sendiri dan
     * tokennya membawa outlet_id + role cashier. Ini yang melunasi kruk mint
     * token manual di F4d dan bikin RBAC kasir F8a berhenti teoritis.
     */
    public function test_kasir_bikinan_bisa_login_dan_tokennya_membawa_outlet(): void
    {
        $owner = $this->registerOwner();
        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'kasir1@kopisenja.test',
            'password' => 'Kasir12345',
        ])
            ->assertOk()
            ->assertJsonStructure(['access_token'])
            ->assertJsonPath('user.role', 'cashier')
            ->assertJsonPath('user.tenant_id', $owner['user']['tenant_id'])
            ->assertJsonPath('user.outlet_id', $owner['user']['outlet_id']);
    }

    public function test_email_duplikat_ditolak(): void
    {
        $owner = $this->registerOwner();
        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** Policy password sama dengan register (min 8 + huruf besar-kecil + angka). */
    public function test_password_lemah_ditolak(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload(['password' => 'lemahsekali']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    // ---------- LIST & UPDATE ----------

    public function test_daftar_staff_terisolasi_per_tenant(): void
    {
        $ownerA = $this->registerOwner();
        $this->withTokenFresh($ownerA['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated();

        $ownerB = $this->registerOwnerB();
        $this->withTokenFresh($ownerB['access_token'])
            ->postJson('/api/staff', $this->staffPayload([
                'name' => 'Kasir Tenant B',
                'email' => 'kasir@kopipagi.test',
            ]))
            ->assertCreated();

        $emails = collect($this->withTokenFresh($ownerA['access_token'])->getJson('/api/staff')->assertOk()->json())
            ->pluck('email')
            ->all();

        $this->assertContains('kasir1@kopisenja.test', $emails);
        $this->assertNotContains('kasir@kopipagi.test', $emails, 'Staff tenant lain tidak boleh bocor.');
    }

    public function test_update_staff_tenant_lain_menghasilkan_404(): void
    {
        $ownerA = $this->registerOwner();
        $ownerB = $this->registerOwnerB();

        $kasirB = $this->withTokenFresh($ownerB['access_token'])
            ->postJson('/api/staff', $this->staffPayload(['email' => 'kasir@kopipagi.test']))
            ->assertCreated()
            ->json('id');

        $this->withTokenFresh($ownerA['access_token'])
            ->putJson("/api/staff/{$kasirB}", ['name' => 'Diretas'])
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $kasirB, 'name' => 'Kasir Satu']);
    }

    /**
     * Anti self-lockout: owner satu-satunya per tenant hari ini. Kalau dia bisa
     * menonaktifkan dirinya sendiri, tenant mati permanen — tak ada akun lain
     * yang berwenang menghidupkannya kembali.
     */
    public function test_owner_tak_bisa_menonaktifkan_dirinya_sendiri(): void
    {
        $owner = $this->registerOwner();

        $this->withTokenFresh($owner['access_token'])
            ->putJson("/api/staff/{$owner['user']['id']}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $owner['user']['id'], 'is_active' => true]);
    }

    /**
     * Suspend harus benar-benar menutup pintu, bukan sekadar mengubah kolom.
     *
     * Plafon yang diterima sadar: token yang TERLANJUR terbit masih sah sampai
     * TTL habis (15 menit) — belum ada denylist bersama. Yang dikunci di sini:
     * kasir yang disuspend tak bisa memperoleh token BARU.
     */
    public function test_kasir_yang_disuspend_tak_bisa_login_lagi(): void
    {
        $owner = $this->registerOwner();
        $kasirId = $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated()
            ->json('id');

        $this->withTokenFresh($owner['access_token'])
            ->putJson("/api/staff/{$kasirId}", ['is_active' => false])
            ->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'kasir1@kopisenja.test',
            'password' => 'Kasir12345',
        ])->assertUnauthorized();
    }

    // ---------- OTORISASI ----------

    /**
     * Suspend harus mencabut hak istimewa SEKETIKA, bukan menunggu token lama
     * kedaluwarsa. Owner dinonaktifkan langsung lewat DB karena API sengaja
     * melarangnya menonaktifkan dirinya sendiri; tokennya sengaja TIDAK
     * diperbarui, jadi ini persis skenario "token terlanjur terbit".
     */
    public function test_akun_nonaktif_ditolak_walau_tokennya_masih_berlaku(): void
    {
        $owner = $this->registerOwner();

        User::whereKey($owner['user']['id'])->update(['is_active' => false]);

        $this->withTokenFresh($owner['access_token'])
            ->getJson('/api/staff')
            ->assertForbidden();
    }

    public function test_kasir_tak_boleh_mengelola_staff(): void
    {
        $owner = $this->registerOwner();
        $this->withTokenFresh($owner['access_token'])
            ->postJson('/api/staff', $this->staffPayload())
            ->assertCreated();

        $kasirToken = $this->postJson('/api/auth/login', [
            'email' => 'kasir1@kopisenja.test',
            'password' => 'Kasir12345',
        ])->assertOk()->json('access_token');

        $this->withTokenFresh($kasirToken)->getJson('/api/staff')->assertForbidden();
        $this->withTokenFresh($kasirToken)
            ->postJson('/api/staff', $this->staffPayload(['email' => 'kasir2@kopisenja.test']))
            ->assertForbidden();
    }

    public function test_tamu_tak_bisa_mengakses_manajemen_staff(): void
    {
        $this->getJson('/api/staff')->assertUnauthorized();
        $this->postJson('/api/staff', $this->staffPayload())->assertUnauthorized();
    }
}
