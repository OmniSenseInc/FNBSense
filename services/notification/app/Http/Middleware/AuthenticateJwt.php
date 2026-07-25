<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Verifikasi JWT RS256 secara stateless; Reporting hanya memegang public key IAM. */
class AuthenticateJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return $this->unauthorized();
        }

        $claims = $this->verify($matches[1]);
        if ($claims === null
            || ! $this->validSubject($claims['sub'] ?? null)
            || ! $this->uuid($claims['tenant_id'] ?? null)
            || ! $this->uuid($claims['outlet_id'] ?? null)
            || ! $this->nonEmptyString($claims['role'] ?? null)) {
            return $this->unauthorized();
        }

        $request->attributes->set('tenant_id', $claims['tenant_id']);
        $request->attributes->set('outlet_id', $claims['outlet_id']);
        $request->attributes->set('role', $claims['role'] ?? null);
        $request->attributes->set('user_id', $claims['sub'] ?? null);

        return $next($request);
    }

    private function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);
        if (($header['alg'] ?? null) !== 'RS256' || $claims === null || $signature === null) {
            return null;
        }

        $key = $this->publicKey();
        if ($key === null || openssl_verify("{$encodedHeader}.{$encodedPayload}", $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $now = time();
        $leeway = max(0, (int) config('auth.jwt_leeway_seconds', 30));
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] <= $now - $leeway) {
            return null;
        }
        if (! is_numeric($claims['iat'] ?? null) || (int) $claims['iat'] > $now + $leeway) {
            return null;
        }
        if (isset($claims['nbf']) && (! is_numeric($claims['nbf']) || (int) $claims['nbf'] > $now + $leeway)) {
            return null;
        }

        return $claims;
    }

    private function publicKey(): ?string
    {
        $configured = (string) config('auth.jwt_public_key', '');
        $path = str_starts_with($configured, 'file://') ? substr($configured, 7) : $configured;
        if ($path !== '' && ! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path)) {
            $path = base_path($path);
        }
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private function decodeJson(string $encoded): ?array
    {
        $decoded = $this->base64UrlDecode($encoded);
        if ($decoded === null) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    private function validSubject(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && $value !== '');
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Token tidak valid atau sudah kedaluwarsa.'], 401);
    }
}
