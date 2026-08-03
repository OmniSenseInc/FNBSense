<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\MintsToken;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use MintsToken;
    use RefreshDatabase;

    private function seedNotif(string $tenantId, string $outletId, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'type' => 'low_stock',
            'severity' => 'warning',
            'title' => 'Stok menipis',
            'body' => '1 bahan menipis.',
            'payload' => ['order_id' => 'ORD-1'],
            'source_event_id' => (string) Str::uuid(),
            'created_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * Notifikasi ber-audiens owner tak pernah sampai ke kasir.
     *
     * Ditegakkan di server, bukan di layar: kalau barisnya tetap dikirim ke
     * browser lalu disembunyikan tampilan, kasir yang dilaporkan tinggal
     * membuka DevTools untuk membaca laporan tentang dirinya sendiri.
     */
    public function test_notifikasi_owner_tak_terlihat_kasir(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();

        $this->seedNotif($tenant, $outlet);
        $this->seedNotif($tenant, $outlet, [
            'audience' => Notification::UNTUK_OWNER,
            'type' => 'order_cancelled',
            'title' => 'Pesanan dibatalkan',
        ]);

        $this->getJson('/api/notifications', $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Stok menipis');

        $this->getJson('/api/notifications', $this->authHeaders($tenant, $outlet, 'owner'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Angka lonceng ikut disaring.
     *
     * Kalau tidak, kasir melihat "1 belum dibaca" lalu membuka inbox kosong —
     * dan sejak saat itu ia tahu ada sesuatu yang disembunyikan darinya.
     */
    public function test_angka_belum_dibaca_ikut_menyaring_audiens(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();

        $this->seedNotif($tenant, $outlet);
        $this->seedNotif($tenant, $outlet, ['audience' => Notification::UNTUK_OWNER]);

        $this->getJson('/api/notifications/unread-count', $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->getJson('/api/notifications/unread-count', $this->authHeaders($tenant, $outlet, 'owner'))
            ->assertOk()
            ->assertJsonPath('unread', 2);
    }

    /** Kasir tak bisa memastikan keberadaannya dengan menebak id -> 404. */
    public function test_kasir_menandai_notifikasi_owner_dapat_404(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();

        $notif = $this->seedNotif($tenant, $outlet, ['audience' => Notification::UNTUK_OWNER]);

        $this->postJson(
            "/api/notifications/{$notif->id}/read",
            [],
            $this->authHeaders($tenant, $outlet, 'cashier'),
        )->assertStatus(404);
    }

    public function test_guest_ditolak(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_role_asing_ditolak(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();

        $this->getJson('/api/notifications', $this->authHeaders($tenant, $outlet, 'staff'))
            ->assertStatus(403);
    }

    public function test_kasir_dan_owner_lihat_inbox_scoped_outlet(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();
        $this->seedNotif($tenant, $outlet);
        $this->seedNotif($tenant, (string) Str::uuid());       // outlet lain -> tak muncul
        $this->seedNotif((string) Str::uuid(), $outlet);       // tenant lain -> tak muncul

        foreach (['cashier', 'owner'] as $role) {
            $this->getJson('/api/notifications', $this->authHeaders($tenant, $outlet, $role))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.read', false);
        }
    }

    public function test_read_state_per_user(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();
        $notif = $this->seedNotif($tenant, $outlet);
        $ownerId = (string) Str::uuid();

        $this->postJson("/api/notifications/{$notif->id}/read", [], $this->authHeaders($tenant, $outlet, 'owner', $ownerId))
            ->assertOk();

        $this->getJson('/api/notifications/unread-count', $this->authHeaders($tenant, $outlet, 'owner', $ownerId))
            ->assertOk()->assertJsonPath('unread', 0);

        // kasir (user berbeda) tetap melihatnya sebagai belum dibaca
        $this->getJson('/api/notifications/unread-count', $this->authHeaders($tenant, $outlet, 'cashier'))
            ->assertOk()->assertJsonPath('unread', 1);
    }

    public function test_mark_read_idempoten_dan_scoped(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();
        $notif = $this->seedNotif($tenant, $outlet);
        $headers = $this->authHeaders($tenant, $outlet, 'owner', (string) Str::uuid());

        $this->postJson("/api/notifications/{$notif->id}/read", [], $headers)->assertOk();
        $this->postJson("/api/notifications/{$notif->id}/read", [], $headers)->assertOk(); // ulang -> tetap ok
        $this->assertDatabaseCount('notification_reads', 1);

        $other = $this->seedNotif($tenant, (string) Str::uuid()); // outlet lain
        $this->postJson("/api/notifications/{$other->id}/read", [], $headers)->assertStatus(404);
    }

    public function test_read_all(): void
    {
        $tenant = (string) Str::uuid();
        $outlet = (string) Str::uuid();
        $this->seedNotif($tenant, $outlet);
        $this->seedNotif($tenant, $outlet);
        $headers = $this->authHeaders($tenant, $outlet, 'cashier', (string) Str::uuid());

        $this->postJson('/api/notifications/read-all', [], $headers)
            ->assertOk()->assertJsonPath('data.marked', 2);

        $this->getJson('/api/notifications/unread-count', $headers)
            ->assertOk()->assertJsonPath('unread', 0);
    }
}
