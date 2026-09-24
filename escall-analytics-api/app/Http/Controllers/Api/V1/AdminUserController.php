<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateAnalyticsUserRequest;
use App\Http\Requests\Api\V1\UpdateAnalyticsUserStatusRequest;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('perPage', 25), 1), 100);
        $users = AnalyticsUser::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(fn (AnalyticsUser $user): array => $this->user($user))->values(),
            'pagination' => [
                'page' => $users->currentPage(),
                'perPage' => $users->perPage(),
                'total' => $users->total(),
                'lastPage' => $users->lastPage(),
            ],
        ]);
    }

    public function store(CreateAnalyticsUserRequest $request, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $temporaryPassword = $this->temporaryPassword();
        $data = $request->validated();
        $user = AnalyticsUser::query()->create([
            'name' => trim((string) $data['name']),
            'email' => mb_strtolower((string) $data['email']),
            'role' => $data['role'],
            'password' => $temporaryPassword,
            'is_active' => true,
            'must_change_password' => true,
            'created_by' => $actor->id,
        ]);
        $audit->log($request, 'user_created', $actor, AnalyticsUser::class, $user->id, ['role' => $user->role]);

        return response()->json([
            'user' => $this->user($user),
            'temporaryPassword' => $temporaryPassword,
        ], 201);
    }

    public function updateStatus(UpdateAnalyticsUserStatusRequest $request, AnalyticsUser $user, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $isActive = (bool) $request->validated('isActive');

        if ($actor->is($user) && ! $isActive) {
            return response()->json(['message' => 'No puedes desactivar tu propio usuario.'], 422);
        }

        $user->forceFill(['is_active' => $isActive])->save();
        $audit->log($request, $isActive ? 'user_activated' : 'user_deactivated', $actor, AnalyticsUser::class, $user->id);

        return response()->json(['user' => $this->user($user)]);
    }

    public function resetPassword(Request $request, AnalyticsUser $user, AnalyticsAuditService $audit): JsonResponse
    {
        /** @var AnalyticsUser $actor */
        $actor = $request->user();
        $temporaryPassword = $this->temporaryPassword();
        $user->forceFill([
            'password' => $temporaryPassword,
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();
        $audit->log($request, 'password_reset', $actor, AnalyticsUser::class, $user->id);

        return response()->json(['temporaryPassword' => $temporaryPassword]);
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
            'createdAt' => $user->created_at?->toIso8601String(),
        ];
    }

    private function temporaryPassword(): string
    {
        return Str::password(20, true, true, true, false);
    }
}
