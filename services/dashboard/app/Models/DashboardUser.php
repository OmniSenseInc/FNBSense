<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\GenericUser;

/**
 * Representasi user IAM di dalam session Dashboard.
 *
 * Tidak disimpan ke database lokal: IAM tetap satu-satunya sumber kebenaran user.
 */
class DashboardUser extends GenericUser implements FilamentUser, HasAvatar, HasName
{
    public static function fromIam(array $profile): self
    {
        return new self([
            'id' => $profile['id'],
            'name' => $profile['name'],
            'email' => $profile['email'],
            'role' => $profile['role'],
            'tenant_id' => $profile['tenant_id'],
            'outlet_id' => $profile['outlet_id'],
            'password' => '',
            'remember_token' => null,
        ]);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->role === 'owner'
            && filled($this->tenant_id)
            && filled($this->outlet_id);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return null;
    }

    /**
     * Filament memakai konvensi model ini untuk nama channel notifikasi.
     */
    public function getKey(): string
    {
        return (string) $this->getAuthIdentifier();
    }
}
