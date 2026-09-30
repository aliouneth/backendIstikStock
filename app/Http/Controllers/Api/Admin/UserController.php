<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * GET /api/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('subscription')->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($request->query('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->paginate((int) $request->integer('per_page', 25))->withQueryString();

        return response()->json([
            'success' => true,
            'data' => collect($users->items())->map(fn (User $user): array => $user->toApiArray())->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * POST /api/admin/users
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $user = User::query()->create([
            ...$data,
            'password' => $data['password'],
            'role' => $data['role'] ?? User::ROLE_USER,
            'is_active' => $request->boolean('is_active', true),
            'subscription_id' => $data['subscription_id'] ?? Entitlements::defaultSubscription()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created.',
            'data' => $user->load('subscription')->toApiArray(),
        ], 201);
    }

    /**
     * PATCH /api/admin/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user, false);

        if ($user->id === $request->user()?->id) {
            if (array_key_exists('role', $data) && $data['role'] !== User::ROLE_ADMIN) {
                return $this->error('You cannot remove your own administrator role.', 422);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                return $this->error('You cannot deactivate your own account.', 422);
            }
        }

        if (array_key_exists('role', $data) && $data['role'] !== User::ROLE_ADMIN) {
            $this->demoteLastAdmin($user);
        }

        if (array_key_exists('password', $data) && ($data['password'] ?? null) === null) {
            unset($data['password']);
        }

        if (array_key_exists('is_active', $request->all())) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $user->fill($data)->save();

        return response()->json([
            'success' => true,
            'message' => 'User updated.',
            'data' => $user->refresh()->load('subscription')->toApiArray(),
        ]);
    }

    /**
     * DELETE /api/admin/users/{user}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()?->id) {
            return $this->error('You cannot delete your own account.', 422);
        }

        if ($user->isAdmin() && $this->adminCount() <= 1) {
            return $this->error('At least one administrator must remain.', 422);
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'User deleted.']);
    }

    /**
     * POST /api/admin/users/{user}/reset-password
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', Password::min(8)],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'api_token_hash' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Password reset. The user must sign in again.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null, bool $creating = true): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'email' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'email',
                'max:190',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$creating ? 'required' : 'sometimes', 'string', Password::min(8)],
            'role' => ['sometimes', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'subscription_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subscriptions', 'id')],
            'subscription_expires_at' => ['sometimes', 'nullable', 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];

        $data = $request->validate($rules);

        if (array_key_exists('auto_renew', $request->all())) {
            $data['auto_renew'] = $request->boolean('auto_renew');
        }

        return $data;
    }

    private function adminCount(): int
    {
        return User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->count();
    }

    private function demoteLastAdmin(User $user): void
    {
        if ($user->isAdmin() && $this->adminCount() <= 1) {
            abort(422, 'At least one administrator must remain.');
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $message, 'message' => $message], $status);
    }
}
