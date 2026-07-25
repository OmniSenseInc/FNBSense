<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Str;

/**
 * Membuat JWT RS256 valid untuk test — ditandatangani dengan PRIVATE key IAM
 * (JWT_PRIVATE_KEY di phpunit.xml), meniru token asli terbitan service IAM.
 * Menandatangani manual via openssl agar tak bergantung paket jwt.
 */
trait MintsToken
{
    /**
     * @return array<string, string>
     */
    protected function authHeaders(
        string $tenantId,
        string $outletId,
        string $role = 'owner',
        ?string $userId = null,
    ): array {
        return ['Authorization' => 'Bearer '.$this->mintToken($tenantId, $outletId, $role, $userId)];
    }

    protected function mintToken(
        string $tenantId,
        string $outletId,
        string $role = 'owner',
        ?string $userId = null,
    ): string {
        $now = time();
        $segments = [
            $this->b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64((string) json_encode([
                'sub' => $userId ?? (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
                'role' => $role,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        openssl_sign(implode('.', $segments), $signature, $this->privateKey(), OPENSSL_ALGO_SHA256);
        $segments[] = $this->b64($signature);

        return implode('.', $segments);
    }

    private function privateKey(): string
    {
        $configured = (string) env('JWT_PRIVATE_KEY', '');
        $path = str_starts_with($configured, 'file://') ? substr($configured, 7) : $configured;
        if ($path !== '' && ! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path)) {
            $path = base_path($path);
        }

        return (string) file_get_contents($path);
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
