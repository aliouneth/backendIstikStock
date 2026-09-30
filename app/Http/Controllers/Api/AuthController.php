<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_USER,
            'is_active' => true,
            'subscription_id' => Entitlements::defaultSubscription()?->id,
        ]);

        return $this->issue($user, 201, 'Account created.');
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user instanceof User || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact an administrator.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issue($user, 200, 'Signed in.');
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['api_token_hash' => null])->save();
        }

        return response()->json(['success' => true, 'message' => 'Signed out.']);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->accessPayload($request->user()));
    }

    private function issue(User $user, int $status, string $message): JsonResponse
    {
        $token = User::issueToken();
        $user->forceFill(['api_token_hash' => $token['hash']])->save();

        return response()->json(
            $this->accessPayload($user) + ['token' => $token['plain'], 'message' => $message],
            $status
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function accessPayload(?User $user): array
    {
        $features = Entitlements::for($user);

        return [
            'success' => true,
            'authenticated' => $user instanceof User,
            'user' => $user?->toApiArray(),
            'features' => $features,
            'available_features' => Features::labels(),
            'plans' => Subscription::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get()
                ->map(fn (Subscription $plan): array => $plan->toApiArray())
                ->all(),
        ];
    }
}
