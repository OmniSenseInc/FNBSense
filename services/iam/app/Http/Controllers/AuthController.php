<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

class AuthController extends Controller
{
    /** Setiap tenant baru lahir dengan satu outlet ini — owner langsung terikat padanya. */
    private const DEFAULT_OUTLET_NAME = 'Outlet Utama';

    /**
     * Registrasi tenant baru + user owner-nya, lalu kembalikan token.
     *
     * Catatan keamanan: input TIDAK boleh menentukan role atau tenant_id.
     * Role dipaksa Owner dan tenant dibuat baru — mencegah privilege
     * escalation (mendaftar sebagai owner tenant milik orang lain).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Tenant + outlet default + owner dibuat atomik: kalau salah satu gagal,
        // semuanya dibatalkan. Owner LANGSUNG terikat outlet default supaya
        // service hilir (Ordering) yang men-scope per outlet bisa dipakai sejak
        // menit pertama — tanpa ini owner.outlet_id null dan semua endpoint
        // ber-outlet menolak 403.
        $user = DB::transaction(function () use ($validated) {
            $tenant = Tenant::create([
                'name' => $validated['business_name'],
                'slug' => $this->uniqueSlug($validated['business_name']),
                'is_active' => true,
            ]);

            $outlet = Outlet::create([
                'tenant_id' => $tenant->id,
                'name' => self::DEFAULT_OUTLET_NAME,
                'is_active' => true,
            ]);

            return User::create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => UserRole::Owner,
                'is_active' => true,
            ]);
        });

        $token = $this->guard()->login($user);

        return $this->respondWithToken($token, $user, 201);
    }

    /**
     * Login via email + password. Hanya user aktif yang bisa masuk —
     * `is_active` ikut difilter di query attempt, jadi user nonaktif
     * ditolak tanpa membocorkan status akunnya.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $token = $this->guard()->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ]);

        if (! $token) {
            return response()->json(['message' => 'Email atau password salah.'], 401);
        }

        return $this->respondWithToken($token, $this->guard()->user());
    }

    /**
     * Profil user pemilik token saat ini.
     */
    public function me(): JsonResponse
    {
        return response()->json($this->guard()->user());
    }

    /**
     * Logout: token saat ini di-invalidate (masuk blacklist).
     */
    public function logout(): JsonResponse
    {
        $this->guard()->logout();

        return response()->json(['message' => 'Berhasil logout.']);
    }

    /**
     * Tukar token lama dengan token baru; token lama otomatis invalid.
     */
    public function refresh(): JsonResponse
    {
        $token = $this->guard()->refresh();

        return $this->respondWithToken($token, $this->guard()->user());
    }

    /**
     * Guard "api" yang sudah pasti bertipe JWTGuard — memberi tahu IDE
     * bahwa method login/attempt/refresh/factory tersedia.
     */
    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard;
    }

    /**
     * Bentuk respons token yang seragam untuk register/login/refresh.
     */
    private function respondWithToken(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
                'tenant_id' => $user->tenant_id,
                'outlet_id' => $user->outlet_id,
            ],
        ], $status);
    }

    /**
     * Bangun slug unik dari nama bisnis (kopi-senja, kopi-senja-1, ...).
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $suffix = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
