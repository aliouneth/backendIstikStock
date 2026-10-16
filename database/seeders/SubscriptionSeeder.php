<?php

namespace Database\Seeders;

use App\Models\Subscription;
use App\Models\User;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function plans(): array
    {
        return [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Daily picks and performance history for new investors.',
                'price' => 0,
                'billing_period' => 'monthly',
                'is_default' => true,
                'sort_order' => 0,
                'features' => [
                    Features::TODAY_PICKS,
                    Features::HISTORY,
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Adds the news surge scanner, graphs and full model reasoning.',
                'price' => 29,
                'annual_price' => 290,
                'billing_period' => 'monthly',
                'is_default' => false,
                'sort_order' => 1,
                'features' => [
                    Features::TODAY_PICKS,
                    Features::HISTORY,
                    Features::HISTORY_GRAPHS,
                    Features::NEWS_SURGE,
                    Features::FULL_RATIONALE,
                    Features::MODEL_METRICS,
                ],
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'Everything, including daily analysis runs and model metrics.',
                'price' => 79,
                'annual_price' => 790,
                'billing_period' => 'monthly',
                'is_default' => false,
                'sort_order' => 2,
                'features' => Features::keys(),
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::plans() as $plan) {
            Subscription::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'price' => $plan['price'],
                    'annual_price' => $plan['annual_price'] ?? null,
                    'billing_period' => $plan['billing_period'],
                    'is_default' => $plan['is_default'],
                    'is_active' => true,
                    'sort_order' => $plan['sort_order'],
                    'features' => $plan['features'],
                ]
            );
        }

        Entitlements::flushCache();

        $this->seedAdministrator();
    }

    private function seedAdministrator(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@istikstocks.test');
        $password = env('ADMIN_PASSWORD', 'ChangeMe123!');

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            $user->forceFill([
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                // The User model casts `password` as `hashed`; assign plaintext here.
                'password' => $password,
            ])->save();
        } else {
            User::query()->create([
                'name' => env('ADMIN_NAME', 'IstikStocks Admin'),
                'email' => $email,
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'subscription_id' => Entitlements::defaultSubscription()?->id,
            ]);
        }

        $this->command?->info(sprintf('Administrator ready: %s', $email));
    }
}
