<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AuthController extends Controller
{
    public function login(LoginRequest $request, AnalyticsAuditService $audit): JsonResponse
    {
        $credentials = $request->validated();
        $email = mb_strtolower((string) $credentials['email']);
        $user = AnalyticsUser::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check((string) $credentials['password'], $user->password)) {
            $audit->log($request, 'login_failed', null, null, null, ['emailHash' => hash('sha256', $email)]);

            return response()->json(['message' => 'Las credenciales ingresadas no son válidas.'], 422);
        }

        if (! $user->is_active) {
            $audit->log($request, 'login_inactive', $user);

            return response()->json(['message' => 'La cuenta se encuentra desactivada.'], 403);
        }

        Auth::guard('analytics')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->log($request, 'login_success', $user);

        return response()->json(['user' => $this->user($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AnalyticsUser $user */
        $user = $request->user();

        return response()->json(['user' => $this->user($user)]);
    }

    public function logout(Request $request, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser|null $user */
        $user = $request->user();
        $audit->log($request, 'logout', $user);
        Auth::guard('analytics')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function changePassword(ChangePasswordRequest $request, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $user */
        $user = $request->user();
        $user->forceFill([
            'password' => (string) $request->validated('password'),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $audit->log($request, 'password_changed', $user);

        return response()->json(['user' => $this->user($user->fresh())]);
    }

    /** @return array<string, bool|int|string|null> */
    private function user(AnalyticsUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'isActive' => $user->is_active,
            'mustChangePassword' => $user->must_change_password,
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
