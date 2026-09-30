<?php

namespace App\Http\Middleware;

use App\Support\Entitlements;
use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! Features::isValid($feature)) {
            abort(500, "Unknown feature [{$feature}].");
        }

        if (Entitlements::allows($request->user(), $feature)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your subscription does not include '.Features::labels()[$feature].'.',
            'error' => 'Your subscription does not include '.Features::labels()[$feature].'.',
            'feature' => $feature,
            'required_feature' => $feature,
            'upgrade_required' => true,
        ], 403);
    }
}
