<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUser;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = [
            'id' => 42,
            'name' => 'Owner Demo',
            'email' => 'owner@example.test',
            'role' => 'owner',
            'tenant_id' => '22222222-2222-4222-8222-222222222222',
            'outlet_id' => '33333333-3333-4333-8333-333333333333',
        ];

        Route::middleware('web')->post('/_testing/login', function (Request $request) {
            $authenticated = Auth::attempt($request->only('email', 'password'));

            return response()->json(['authenticated' => $authenticated], $authenticated ? 200 : 401);
        });

        Route::middleware('web')->get('/_testing/session-user', fn () => response()->json([
            'authenticated' => Auth::check(),
        ]));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_owner_can_authenticate_through_iam_without_a_local_user_database(): void
    {
        Http::fake([
            'http://iam.test/api/auth/login' => Http::response([
                'access_token' => 'signed-owner-token',
                'token_type' => 'bearer',
                'expires_in' => 3600,
                'user' => $this->owner,
            ]),
        ]);

        $this->postJson('/_testing/login', [
            'email' => 'owner@example.test',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertSessionHas('dashboard.jwt', 'signed-owner-token')
            ->assertSessionHas('dashboard.token_expires_at')
            ->assertSessionHas('dashboard.user.id', $this->owner['id']);

        $this->assertAuthenticatedAs(DashboardUser::fromIam($this->owner));

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://iam.test/api/auth/login'
            && $request['email'] === 'owner@example.test'
            && $request['password'] === 'secret-password');
    }

    public function test_non_owner_cannot_enter_the_dashboard(): void
    {
        Http::fake([
            'http://iam.test/api/auth/login' => Http::response([
                'access_token' => 'signed-cashier-token',
                'expires_in' => 3600,
                'user' => [...$this->owner, 'role' => 'cashier'],
            ]),
        ]);

        $this->postJson('/_testing/login', [
            'email' => 'cashier@example.test',
            'password' => 'secret-password',
        ])
            ->assertUnauthorized()
            ->assertSessionMissing('dashboard.jwt');

        $this->assertGuest();
    }

    public function test_cashier_profile_cannot_be_restored_as_an_owner_session(): void
    {
        $sessionGuardKey = Auth::guard('web')->getName();

        $this->withSession([
            $sessionGuardKey => $this->owner['id'],
            'dashboard.jwt' => 'signed-cashier-token',
            'dashboard.token_expires_at' => time() + 3600,
            'dashboard.user' => [...$this->owner, 'role' => 'cashier'],
        ])
            ->get('/_testing/session-user')
            ->assertOk()
            ->assertJsonPath('authenticated', false);

        $this->assertGuest();
    }

    public function test_expired_iam_token_cannot_restore_an_owner_session(): void
    {
        $sessionGuardKey = Auth::guard('web')->getName();

        $this->withSession([
            $sessionGuardKey => $this->owner['id'],
            'dashboard.jwt' => 'expired-owner-token',
            'dashboard.token_expires_at' => time() - 1,
            'dashboard.user' => $this->owner,
        ])
            ->get('/_testing/session-user')
            ->assertOk()
            ->assertJsonPath('authenticated', false);

        $this->assertGuest();
    }

    public function test_logout_revokes_the_iam_token_and_clears_dashboard_session(): void
    {
        Http::fake([
            'http://iam.test/api/auth/logout' => Http::response(['message' => 'Successfully logged out']),
        ]);

        $this->actingAs(DashboardUser::fromIam($this->owner))
            ->withSession([
                'dashboard.jwt' => 'signed-owner-token',
                'dashboard.token_expires_at' => time() + 3600,
                'dashboard.user' => $this->owner,
            ])
            ->post('/admin/logout')
            ->assertRedirect('/admin/login')
            ->assertSessionMissing('dashboard.jwt')
            ->assertSessionMissing('dashboard.token_expires_at')
            ->assertSessionMissing('dashboard.user');

        $this->assertGuest();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://iam.test/api/auth/logout'
            && $request->hasHeader('Authorization', 'Bearer signed-owner-token'));
    }

    public function test_owner_sees_reporting_data_scoped_by_the_iam_token(): void
    {
        Http::fake([
            'http://reporting.test/api/summary*' => Http::response(['data' => [
                'revenue' => 150000,
                'transactions' => 5,
                'average_order_value' => 30000,
                'by_payment_method' => [
                    ['method' => 'cash', 'transactions' => 2, 'revenue' => 50000],
                    ['method' => 'qris_static', 'transactions' => 3, 'revenue' => 100000],
                ],
            ]]),
            'http://reporting.test/api/trends/daily*' => Http::response(['data' => [
                'points' => [
                    ['date' => '2026-07-22', 'transactions' => 2, 'revenue' => 50000],
                    ['date' => '2026-07-23', 'transactions' => 3, 'revenue' => 100000],
                ],
            ]]),
            'http://reporting.test/api/products/top*' => Http::response(['data' => [
                'products' => [[
                    'product_id' => '44444444-4444-4444-8444-444444444444',
                    'product_name' => 'Es Kopi Susu',
                    'qty' => 8,
                    'revenue' => 120000,
                ]],
            ]]),
        ]);

        $this->actingAs(DashboardUser::fromIam($this->owner))
            ->withSession([
                'dashboard.jwt' => 'signed-owner-token',
                'dashboard.token_expires_at' => time() + 3600,
                'dashboard.user' => $this->owner,
            ])
            ->get('/admin')
            ->assertOk()
            ->assertSee('Ringkasan Bisnis')
            ->assertSee('Omzet')
            ->assertSee('Rp150.000')
            ->assertSee('Produk terlaris')
            ->assertSee('Es Kopi Susu');

        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with(
            $request->url(),
            'http://reporting.test/api/',
        )
            && $request->hasHeader('Authorization', 'Bearer signed-owner-token')
            && ! array_key_exists('tenant_id', $request->data())
            && ! array_key_exists('outlet_id', $request->data()));
    }
}
