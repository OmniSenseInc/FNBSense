<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\DashboardUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

class IamUserProvider implements UserProvider
{
    private ?DashboardUser $pendingUser = null;

    private ?string $pendingEmail = null;

    public function retrieveById($identifier): ?Authenticatable
    {
        $profile = session('dashboard.user');
        $expiresAt = session('dashboard.token_expires_at');

        if (! is_array($profile)
            || ! is_numeric($expiresAt) || (int) $expiresAt <= time()
            || (string) ($profile['id'] ?? '') !== (string) $identifier) {
            return null;
        }

        return $this->validProfile($profile) ? DashboardUser::fromIam($profile) : null;
    }

    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] $token): void
    {
        // Remember-me sengaja tidak didukung: session Dashboard mengikuti TTL normal.
    }

    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return null;
        }

        if ($this->pendingUser !== null && $this->pendingEmail === $email) {
            return $this->pendingUser;
        }

        try {
            $response = Http::baseUrl((string) config('services.iam.url'))
                ->acceptJson()
                ->timeout(8)
                ->post('/api/auth/login', compact('email', 'password'));
        } catch (ConnectionException $exception) {
            Log::warning('dashboard.login: IAM tidak tersedia.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $profile = $response->json('user');
        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! $response->successful()
            || ! is_string($token) || $token === ''
            || ! is_numeric($expiresIn) || (int) $expiresIn <= 0
            || ! is_array($profile) || ! $this->validProfile($profile)) {
            return null;
        }

        session([
            'dashboard.jwt' => $token,
            'dashboard.token_expires_at' => time() + (int) $expiresIn,
            'dashboard.user' => $profile,
        ]);

        $this->pendingEmail = $email;

        return $this->pendingUser = DashboardUser::fromIam($profile);
    }

    public function validateCredentials(
        Authenticatable $user,
        #[SensitiveParameter] array $credentials,
    ): bool {
        return $user instanceof DashboardUser
            && $this->pendingUser?->getAuthIdentifier() === $user->getAuthIdentifier()
            && ($credentials['email'] ?? null) === $this->pendingEmail;
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[SensitiveParameter] array $credentials,
        bool $force = false,
    ): void {
        // Password dimiliki IAM; Dashboard tidak menyimpan atau melakukan rehash.
    }

    private function validProfile(array $profile): bool
    {
        $id = $profile['id'] ?? null;
        if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
            return false;
        }

        foreach (['name', 'email', 'role', 'tenant_id', 'outlet_id'] as $field) {
            if (! is_string($profile[$field] ?? null) || $profile[$field] === '') {
                return false;
            }
        }

        return $profile['role'] === 'owner';
    }
}
