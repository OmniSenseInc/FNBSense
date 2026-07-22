<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Klaim scoping dari JWT (di-set AuthenticateJwt), bukan dari body → anti-IDOR. */
    protected function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }

    protected function outletId(Request $request): string
    {
        return $request->attributes->get('outlet_id');
    }

    protected function userId(Request $request): ?string
    {
        return $request->attributes->get('user_id');
    }
}
