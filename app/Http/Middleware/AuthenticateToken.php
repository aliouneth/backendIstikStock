<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->token($request);

        if ($plain === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Unauthenticated. Please sign in.',
            ], 401);
        }

        $user = User::query()
            ->where('api_token_hash', User::hashToken($plain))
            ->first();

        if (! $user instanceof User) {
            return $this->deny('Your session is no longer valid. Please sign in again.');
        }

        if (! $user->isActive()) {
            return $this->deny('This account has been deactivated.');
        }

        auth()->setUser($user);
        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function token(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $value = trim(substr($header, 7));

            return $value === '' ? null : $value;
        }

        $query = $request->query('token');

        return is_string($query) && $query !== '' ? $query : null;
    }

    private function deny(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => $message,
        ], 401);
    }
}
