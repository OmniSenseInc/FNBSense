<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Schema;

class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
            ]);
    }

    public function getHeading(): string
    {
        return 'Masuk ke FNBSense';
    }

    public function getSubheading(): string
    {
        return 'Gunakan akun owner yang terdaftar di IAM.';
    }
}
