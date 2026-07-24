<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Membuat JWT RS256 valid untuk test — ditandatangani dengan PRIVATE key IAM
 * (lihat phpunit.xml), meniru token asli yang diterbitkan service IAM.
 */
trait MintsToken
{
    protected function mintToken(
        string $tenantId,
        string $role = 'owner',
        ?string $userId = null,
        ?string $outletId = null,
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
        string $role = 'owner',
        ?string $outletId = null,
    ): array {
        return ['Authorization' => 'Bearer '.$this->mintToken(
            $tenantId,
            $role,
            outletId: $outletId,
        )];
    }
}
