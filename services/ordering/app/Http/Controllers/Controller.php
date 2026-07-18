<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /**
     * Tenant pemilik request, dari klaim JWT.
     *
     * AuthenticateJwt sudah menolak token tanpa tenant_id, jadi di sini
     * nilainya dijamin ada selama route dipasangi middleware 'jwt'.
     */
    protected function tenantId(Request $request): string
    {
        return $request->attributes->get('tenant_id');
    }

    /**
     * Outlet tempat request ini bekerja, dari klaim JWT.
     *
     * Sengaja ditegakkan di SINI, bukan di middleware: klaim outlet_id boleh
     * null (owner tanpa outlet), tapi setiap endpoint Ordering bekerja pada
     * satu outlet konkret. Akun tanpa outlet = salah konfigurasi di IAM ->
     * 403 dengan pesan yang menjelaskan, bukan query diam-diam ke
     * `outlet_id IS NULL` yang balik dengan data kosong tanpa sebab jelas.
     */
    protected function outletId(Request $request): string
    {
        $outletId = $request->attributes->get('outlet_id');

        if (empty($outletId)) {
            throw new HttpException(403, 'Akun ini belum terikat ke outlet mana pun.');
        }

        return $outletId;
    }

    /**
     * User (kasir/owner) yang melakukan aksi, dari klaim `sub` JWT.
     *
     * AuthenticateJwt menaruhnya di attribute `user_id`. Dipakai mengisi
     * `confirmed_by` saat PAID — kontrak event mewajibkan jejak siapa yang
     * mengonfirmasi. Selama route dipasangi 'jwt', nilainya dijamin ada.
     */
    protected function userId(Request $request): string
    {
        return $request->attributes->get('user_id');
    }
}
