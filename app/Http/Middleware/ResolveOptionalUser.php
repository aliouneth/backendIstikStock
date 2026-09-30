<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOptionalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        $plain = str_starts_with($header, 'Bearer ')
            ? trim(substr($header, 7))
            : (string) $request->query('token', '');

        if ($plain !== '') {
            $user = User::query()
                ->where('api_token_hash', User::hashToken($plain))
                ->where('is_active', true)
                ->first();

            if ($user instanceof User) {
                auth()->setUser($user);
                $request->setUserResolver(fn (): User => $user);

                return $next($request);
            }
        }

        $request->setUserResolver(fn (): ?User => null);

        return $next($request);
    }
}
