<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    /**
     * GET /api/access
     * Public: reports which features the caller can use right now, plus the
     * list of selectable plans. Works for guests and signed-in users.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $features = Entitlements::for($user);

        return response()->json([
            'success' => true,
            'authenticated' => $user instanceof User,
            'user' => $user?->toApiArray(),
            'features' => $features,
            'available_features' => Features::labels(),
            'locked_features' => array_values(array_diff(Features::keys(), $features)),
            'plans' => Subscription::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get()
                ->map(fn (Subscription $plan): array => $plan->toApiArray())
                ->all(),
        ]);
    }
}
