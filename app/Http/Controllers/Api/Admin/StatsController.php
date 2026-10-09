<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyRecommendation;
use App\Models\ModelRun;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    /**
     * GET /api/admin/stats
     *
     * Read-only overview of the site for the administration area.
     */
    public function index(): JsonResponse
    {
        $now = Carbon::now();

        $lastRun = ModelRun::query()->latest('started_at')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => $now->toIso8601String(),
                'users' => [
                    'total' => User::query()->count(),
                    'active' => User::query()->where('is_active', true)->count(),
                    'inactive' => User::query()->where('is_active', false)->count(),
                    'admins' => User::query()->where('role', User::ROLE_ADMIN)->count(),
                    'new_today' => User::query()->where('created_at', '>=', $now->copy()->startOfDay())->count(),
                    'new_7d' => User::query()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
                    'new_30d' => User::query()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
                    'seen_7d' => User::query()->where('last_login_at', '>=', $now->copy()->subDays(7))->count(),
                    'never_signed_in' => User::query()->whereNull('last_login_at')->count(),
                ],
                'plans' => Subscription::query()
                    ->orderBy('sort_order')
                    ->orderBy('price')
                    ->get()
                    ->map(fn (Subscription $plan): array => [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'slug' => $plan->slug,
                        'price' => (float) $plan->price,
                        'users' => User::query()->where('subscription_id', $plan->id)->count(),
                    ])
                    ->all(),
                'unassigned_plan' => User::query()->whereNull('subscription_id')->count(),
                'watchlists' => [
                    'lists' => Watchlist::query()->count(),
                    'items' => WatchlistItem::query()->count(),
                    'users' => Watchlist::query()->distinct()->count('user_id'),
                ],
                'billing' => [
                    'paid' => SubscriptionCheckout::query()->where('status', SubscriptionCheckout::STATUS_PAID)->count(),
                    'pending' => SubscriptionCheckout::query()->where('status', SubscriptionCheckout::STATUS_PENDING)->count(),
                    'failed' => SubscriptionCheckout::query()->where('status', SubscriptionCheckout::STATUS_FAILED)->count(),
                    'revenue' => (float) SubscriptionCheckout::query()->where('status', SubscriptionCheckout::STATUS_PAID)->sum('amount'),
                ],
                'content' => [
                    'stocks' => Stock::query()->count(),
                    'recommendations' => DailyRecommendation::query()->count(),
                    'latest_recommendation_date' => DailyRecommendation::query()->max('recommendation_date'),
                    'model_runs' => ModelRun::query()->count(),
                    'last_model_run' => $lastRun === null ? null : [
                        'run_type' => $lastRun->run_type,
                        'status' => $lastRun->status,
                        'started_at' => $lastRun->started_at?->toIso8601String(),
                        'finished_at' => $lastRun->finished_at?->toIso8601String(),
                    ],
                ],
            ],
        ]);
    }
}
