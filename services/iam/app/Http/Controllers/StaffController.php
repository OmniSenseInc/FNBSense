<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            // Owner memilih role staff (boleh kosong = default kasir). 'owner'
            // sengaja DILARANG: owner cuma satu per tenant (pemegang akun), tak
            // bisa dilahirkan lewat jalur staff.
            'role' => ['sometimes', Rule::in([UserRole::Manager->value, UserRole::Cashier->value])],
        ]);

        $staff = User::create([
            'tenant_id' => $owner->tenant_id,
            'outlet_id' => $owner->outlet_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => UserRole::from($validated['role'] ?? UserRole::Cashier->value),
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
            // Reset sandi kasir yang lupa. TANPA sandi lama — owner memang tak
            // tahu, dan itu seluruh gunanya. Sebelum medan ini ada, mengirim
            // `password` ke sini membalas 200 tapi tak berbuat apa-apa sama
            // sekali: ia tak lolos validasi, jadi tak pernah sampai ke update().
            'password' => ['sometimes', Password::defaults()],
            // Promosi/demosi staff. 'owner' tak bisa dipilih di sini — owner
            // satu-satunya adalah pemegang akun, bukan staff yang diatur.
            'role' => ['sometimes', Rule::in([UserRole::Manager->value, UserRole::Cashier->value])],
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

        // Pintu samping yang harus tertutup. Ganti sandi sendiri (POST
        // /auth/password) sengaja menuntut sandi lama supaya token yang bocor
        // tak bisa jadi pengambilalihan akun permanen. Kalau owner boleh
        // mereset sandinya sendiri DI SINI, syarat itu jadi sia-sia: pemegang
        // token curian cukup menembak akunnya sendiri lewat jalur staff.
        if (array_key_exists('password', $validated) && $staff->is($owner)) {
            throw ValidationException::withMessages([
                'password' => 'Untuk mengganti sandimu sendiri, sebutkan sandi lamamu lewat ganti sandi.',
            ]);
        }

        // Owner tak boleh mengubah role-nya sendiri lewat jalur staff: itu jalan
        // menuju tenant tanpa owner (demosi diri ke manager/cashier) yang tak
        // bisa dikembalikan tanpa akses langsung ke basis data.
        if (array_key_exists('role', $validated) && $staff->is($owner)) {
            throw ValidationException::withMessages([
                'role' => 'Role pemilik akun tidak bisa diubah lewat sini.',
            ]);
        }

        $staff->update($validated);

        return response()->json($this->present($staff));
    }

    /**
     * Hapus staff (SOFT delete). Alasan soft, bukan hard: `confirmed_by` &
     * `cancelled_by` di Ordering menunjuk id user ini — menghapus barisnya
     * memutus jejak siapa yang mengonfirmasi/membatalkan order. Soft delete
     * menyembunyikannya dari daftar tanpa merusak audit.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $owner = $this->owner();

        $staff = User::where('tenant_id', $owner->tenant_id)->whereKey($id)->first();

        if (! $staff instanceof User) {
            return response()->json(['message' => 'Staff tidak ditemukan.'], 404);
        }

        // Owner tak boleh menghapus dirinya sendiri — tenant mati permanen.
        if ($staff->is($owner)) {
            throw ValidationException::withMessages([
                'staff' => 'Kamu tidak bisa menghapus akunmu sendiri.',
            ]);
        }

        $staff->delete();

        return response()->json(['message' => 'Karyawan dihapus.']);
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
