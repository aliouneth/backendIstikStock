<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Administrator access required.',
                'error' => 'Administrator access required.',
            ], 403);
        }

        return $next($request);
    }
}
