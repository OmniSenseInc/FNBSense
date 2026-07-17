<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Membuat JWT RS256 valid untuk test — ditandatangani dengan PRIVATE key IAM
 * (lihat phpunit.xml), meniru token asli yang diterbitkan service IAM.
 *
 * Beda dari versi Catalog: klaim `outlet_id` ikut, karena Ordering men-scope
 * datanya per outlet. Sengaja bisa null supaya kasus "akun belum terikat
 * outlet" (owner hasil IAM saat ini) bisa diuji, bukan cuma jalur bahagia.
 */
trait MintsToken
{
    protected function mintToken(
        string $tenantId,
        ?string $outletId = null,
        string $role = 'owner',
        ?string $userId = null,
    ): string {
        $payload = JWTAuth::factory()->customClaims([
            'sub' => $userId ?? (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'role' => $role,
        ])->make();

        return JWTAuth::encode($payload)->get();
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(
        string $tenantId,
        ?string $outletId = null,
        string $role = 'owner',
    ): array {
        return ['Authorization' => 'Bearer '.$this->mintToken($tenantId, $outletId, $role)];
    }
}
