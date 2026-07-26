<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Manajemen staff oleh owner (F-iam-b).
 *
 * Prinsip yang sama dengan register(): input TIDAK boleh menentukan role,
 * tenant, maupun outlet. Ketiganya diisi server dari token — bukan divalidasi
 * dari body, melainkan tidak pernah diterima sama sekali, sehingga IDOR &
 * privilege escalation mustahil secara struktural, bukan karena ada guard.
 */
class StaffController extends Controller
{
    /** Batas baris yang dikembalikan index() — lihat catatan di sana. */
    private const MAX_STAFF_LISTED = 200;

    /**
     * Daftar staff satu tenant. Owner ikut tampil — dia juga staff.
     *
     * ponytail: dikap 200 baris, bukan dipaginasi. Satu outlet kafe tak akan
     * mendekati angka itu, dan kap ini semata mencegah query tanpa batas.
     * Naikkan ke paginasi kalau tenant sebesar itu benar-benar muncul.
     */
    public function index(): JsonResponse
    {
        $staff = User::where('tenant_id', $this->owner()->tenant_id)
            ->orderBy('name')
            ->limit(self::MAX_STAFF_LISTED)
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json($staff);
    }

    /**
     * Buat kasir baru di outlet owner.
     *
     * Outlet sengaja tidak diterima dari body: selama tenant cuma punya satu
     * outlet, parameter itu murni permukaan serang tanpa manfaat. Tambahkan
     * kalau outlet kedua benar-benar ada (lihat F-iam-a).
     */
    public function store(Request $request): JsonResponse
    {
        $owner = $this->owner();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        $staff = User::create([
            'tenant_id' => $owner->tenant_id,
            'outlet_id' => $owner->outlet_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => UserRole::Cashier,
            'is_active' => true,
        ]);

        return response()->json($this->present($staff), 201);
    }

    /**
     * Ubah nama atau status aktif staff.
     *
     * ponytail: suspend hanya menutup penerbitan token BARU — token yang
     * terlanjur terbit tetap sah sampai TTL 15 menit habis. Naikkan ke denylist
     * bersama (Redis) kalau jendela itu jadi terlalu lebar.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $owner = $this->owner();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Scope tenant dulu, baru cari id: staff tenant lain tak terbedakan
        // dari yang tak ada — 404, bukan 403, supaya id tak bisa dienumerasi.
        $staff = User::where('tenant_id', $owner->tenant_id)->whereKey($id)->first();

        if (! $staff instanceof User) {
            return response()->json(['message' => 'Staff tidak ditemukan.'], 404);
        }

        // Owner hari ini satu-satunya per tenant. Kalau dia menonaktifkan
        // dirinya sendiri, tenant mati permanen — tak ada akun berwenang yang
        // tersisa untuk menghidupkannya kembali.
        if (array_key_exists('is_active', $validated) && ! $validated['is_active'] && $staff->is($owner)) {
            throw ValidationException::withMessages([
                'is_active' => 'Kamu tidak bisa menonaktifkan akunmu sendiri.',
            ]);
        }

        $staff->update($validated);

        return response()->json($this->present($staff));
    }

    /** User pemilik token — sudah dijamin owner oleh middleware `role:owner`. */
    private function owner(): User
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $user;
    }

    /**
     * Bentuk respons staff yang seragam. Password & remember_token tak pernah
     * ikut karena hanya field ini yang dipetakan secara eksplisit.
     *
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->value,
            'outlet_id' => $user->outlet_id,
            'is_active' => $user->is_active,
        ];
    }
}
