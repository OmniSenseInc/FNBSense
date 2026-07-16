<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'password' => 'password123',
            'password_confirmation' => 'password123',
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

    // ---------- LOGIN ----------

    public function test_login_berhasil_dengan_kredensial_benar(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'owner@kopisenja.test',
            'password' => 'password123',
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
            'password' => 'password123',
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

    public function test_logout_mematikan_token(): void
    {
        $token = $this->tokenForNewOwner();

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Token yang sama tidak boleh bisa dipakai lagi setelah logout.
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_refresh_menghasilkan_token_baru(): void
    {
        $token = $this->tokenForNewOwner();

        $response = $this->withToken($token)->postJson('/api/auth/refresh');

        $response->assertOk()->assertJsonStructure(['access_token']);
        $this->assertNotSame($token, $response->json('access_token'));
    }
}
