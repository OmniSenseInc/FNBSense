<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function tenantId(Request $request): string
    {
        return (string) $request->attributes->get('tenant_id');
    }

    protected function outletId(Request $request): string
    {
        return (string) $request->attributes->get('outlet_id');
    }
}
