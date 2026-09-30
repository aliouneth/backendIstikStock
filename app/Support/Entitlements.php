<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class Entitlements
{
    private const CACHE_KEY = 'subscriptions.default';

    private const CACHE_TTL = 300;

    /**
     * Feature keys the given user is allowed to use. Guests resolve to the
     * default (Free) plan so the public app stays usable.
     *
     * @return list<string>
     */
    public static function for(?User $user): array
    {
        if ($user instanceof User) {
            return $user->featureKeys();
        }

        return self::guestFeatures();
    }

    public static function allows(?User $user, string $feature): bool
    {
        if (! Features::isValid($feature)) {
            return false;
        }

        return in_array($feature, self::for($user), true);
    }

    /**
     * @return list<string>
     */
    public static function locked(?User $user, string $feature): array
    {
        return self::allows($user, $feature) ? [] : [Features::labels()[$feature] ?? $feature];
    }

    /**
     * @return list<string>
     */
    public static function guestFeatures(): array
    {
        $subscription = self::defaultSubscription();

        return $subscription?->featureKeys() ?? [];
    }

    public static function defaultSubscription(): ?Subscription
    {
        $id = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): ?int {
            $subscription = Subscription::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            return $subscription?->id;
        });

        if ($id === null) {
            return null;
        }

        return Subscription::query()
            ->where('is_active', true)
            ->find($id);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
