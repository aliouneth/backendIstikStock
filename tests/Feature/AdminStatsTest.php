<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $name, bool $isDefault = false, float $price = 0): Subscription
    {
        return Subscription::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'description' => 'Test plan',
            'price' => $price,
            'billing_period' => 'monthly',
            'features' => [Features::TODAY_PICKS],
            'is_active' => true,
            'is_default' => $isDefault,
            'sort_order' => 0,
        ]);
    }

    private function user(?Subscription $plan = null, array $overrides = []): User
    {
        return User::query()->create([
            'name' => $overrides['name'] ?? 'Test User',
            'email' => $overrides['email'] ?? uniqid('user', true).'@example.com',
            'password' => 'Password123!',
            'role' => $overrides['role'] ?? User::ROLE_USER,
            'is_active' => $overrides['is_active'] ?? true,
            'subscription_id' => $plan?->id,
            'last_login_at' => $overrides['last_login_at'] ?? null,
        ]);
    }

    private function tokenFor(User $user): string
    {
        $token = User::issueToken();
        $user->forceFill(['api_token_hash' => $token['hash']])->save();

        return $token['plain'];
    }

    public function test_guests_cannot_view_site_stats(): void
    {
        $this->getJson('/api/admin/stats')->assertStatus(401);
    }

    public function test_non_admins_cannot_view_site_stats(): void
    {
        $token = $this->tokenFor($this->user());

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/stats')
            ->assertStatus(403);
    }

    public function test_admin_sees_site_stats(): void
    {
        $free = $this->plan('Free', isDefault: true);
        $pro = $this->plan('Pro', price: 29);

        $admin = $this->user($free, [
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
            'last_login_at' => now(),
        ]);
        $this->user($free, ['last_login_at' => now()]);
        $this->user($pro, ['is_active' => false, 'last_login_at' => now()]);
        $this->user(null); // no plan, never signed in

        $stock = Stock::query()->create(['ticker' => 'AAA', 'name' => 'AAA Inc', 'source' => 'test']);
        $watchlist = Watchlist::query()->create(['user_id' => $admin->id, 'name' => 'Mine']);
        WatchlistItem::query()->create(['watchlist_id' => $watchlist->id, 'stock_id' => $stock->id]);

        SubscriptionCheckout::forceCreate([
            'user_id' => $admin->id,
            'subscription_id' => $pro->id,
            'plan_name' => 'Pro',
            'billing_period' => 'monthly',
            'amount' => 29,
            'currency' => 'usd',
            'status' => SubscriptionCheckout::STATUS_PAID,
            'driver' => 'manual',
            'paid_at' => now(),
        ]);
        SubscriptionCheckout::forceCreate([
            'user_id' => $admin->id,
            'subscription_id' => $pro->id,
            'plan_name' => 'Pro',
            'billing_period' => 'monthly',
            'amount' => 29,
            'currency' => 'usd',
            'status' => SubscriptionCheckout::STATUS_PENDING,
            'driver' => 'manual',
        ]);

        $token = $this->tokenFor($admin);

        $res = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.users.total', 4)
            ->assertJsonPath('data.users.active', 3)
            ->assertJsonPath('data.users.inactive', 1)
            ->assertJsonPath('data.users.admins', 1)
            ->assertJsonPath('data.users.new_7d', 4)
            ->assertJsonPath('data.users.seen_7d', 3)
            ->assertJsonPath('data.users.never_signed_in', 1)
            ->assertJsonPath('data.unassigned_plan', 1)
            ->assertJsonPath('data.watchlists.lists', 1)
            ->assertJsonPath('data.watchlists.items', 1)
            ->assertJsonPath('data.watchlists.users', 1)
            ->assertJsonPath('data.billing.paid', 1)
            ->assertJsonPath('data.billing.pending', 1)
            ->assertJsonPath('data.billing.failed', 0)
            ->assertJsonPath('data.content.stocks', 1)
            ->assertJsonPath('data.content.recommendations', 0);

        $this->assertEquals(29, $res->json('data.billing.revenue'));
        $this->assertCount(2, $res->json('data.plans'));
    }
}
