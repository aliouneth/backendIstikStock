<?php

namespace Tests\Feature;

use App\Billing\PaymentGateway;
use App\Billing\Pricing;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $name, float $price, ?float $annual = null, bool $isDefault = false): Subscription
    {
        return Subscription::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'description' => 'Test plan',
            'price' => $price,
            'annual_price' => $annual,
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
            'name' => $overrides['name'] ?? 'Buyer',
            'email' => $overrides['email'] ?? 'buyer@example.com',
            'password' => 'Password123!',
            'role' => $overrides['role'] ?? User::ROLE_USER,
            'is_active' => true,
            'subscription_id' => $plan?->id,
        ]);
    }

    private function tokenFor(User $user): string
    {
        $token = User::issueToken();
        $user->forceFill(['api_token_hash' => $token['hash']])->save();

        return $token['plain'];
    }

    private function authed(User $user): static
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user));

        return $this;
    }

    public function test_checkout_requires_authentication(): void
    {
        $pro = $this->plan('Pro', 29, 290);

        $this->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->assertStatus(401);
    }

    public function test_opening_a_checkout_does_not_change_the_plan(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $res = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
            'auto_renew' => false,
        ])->assertCreated();

        // Money has not moved yet, so entitlement must not change.
        $this->assertSame($free->id, $user->fresh()->subscription_id);
        $this->assertSame(SubscriptionCheckout::STATUS_PENDING, $res->json('checkout.status'));
        // JSON round trips 29.0 as an int, so compare numerically.
        $this->assertEqualsWithDelta(29.0, $res->json('checkout.amount'), 0.001);
    }

    public function test_payment_page_quote_reports_pricing_for_both_periods(): void
    {
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $monthly = $this->authed($user)->getJson('/api/billing/quote?'.http_build_query([
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ]))->assertOk();

        $this->assertEqualsWithDelta(29.0, $monthly->json('quote.amount'), 0.001);

        $annual = $this->authed($user)->getJson('/api/billing/quote?'.http_build_query([
            'subscription_id' => $pro->id,
            'billing_period' => 'annual',
        ]))->assertOk();

        $this->assertEqualsWithDelta(290.0, $annual->json('quote.amount'), 0.001);
        $this->assertTrue($annual->json('quote.is_annual'));
        // 12 * 29 = 348 charged monthly, so 58 saved up front.
        $this->assertEqualsWithDelta(58.0, $annual->json('quote.savings'), 0.001);
    }

    public function test_annual_price_falls_back_to_ten_months_when_unset(): void
    {
        $plan = $this->plan('Day Trader', 19);

        $this->assertSame(190.0, Pricing::amountFor($plan, Pricing::ANNUAL));
        $this->assertSame(38.0, Pricing::savingsFor($plan, Pricing::ANNUAL));
    }

    public function test_quote_accepts_auto_renew_as_a_query_string(): void
    {
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user();

        // The payment page sends ?auto_renew=true, which the "boolean" rule
        // would reject because it only accepts 0/1.
        $on = $this->authed($user)->getJson('/api/billing/quote?'.http_build_query([
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
            'auto_renew' => 'true',
        ]))->assertOk();
        $this->assertTrue($on->json('quote.auto_renew'));

        $off = $this->authed($user)->getJson('/api/billing/quote?'.http_build_query([
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
            'auto_renew' => 'false',
        ]))->assertOk();
        $this->assertFalse($off->json('quote.auto_renew'));
    }

    public function test_quote_rejects_an_unknown_billing_period(): void
    {
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $this->authed($user)->getJson('/api/billing/quote?'.http_build_query([
            'subscription_id' => $pro->id,
            'billing_period' => 'weekly',
        ]))->assertStatus(422);
    }

    public function test_confirming_a_paid_checkout_grants_the_plan(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
            'auto_renew' => false,
        ])->json('checkout.id');

        $this->authed($user)
            ->postJson("/api/billing/checkouts/{$checkoutId}/confirm")
            ->assertOk()
            ->assertJsonPath('checkout.status', SubscriptionCheckout::STATUS_PAID);

        $user->refresh();
        $this->assertSame($pro->id, $user->subscription_id);
        $this->assertFalse($user->auto_renew);
        $this->assertNotNull($user->subscription_expires_at);
        $this->assertTrue($user->subscription_expires_at->isFuture());
    }

    public function test_auto_renew_leaves_expiry_open_for_the_renewal_job(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'annual',
            'auto_renew' => true,
        ])->json('checkout.id');

        $this->authed($user)->postJson("/api/billing/checkouts/{$checkoutId}/confirm")->assertOk();

        $user->refresh();
        $this->assertTrue($user->auto_renew);
        $this->assertNull($user->subscription_expires_at);
    }

    public function test_paid_plan_immediately_unlocks_gated_features(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290, false);
        $pro->update(['features' => [Features::TODAY_PICKS, Features::NEWS_SURGE]]);

        $user = $this->user($free);

        // Assert on the entitlement itself: /api/news-scan needs live price
        // data, which RefreshDatabase intentionally does not provide.
        $this->assertNotContains(Features::NEWS_SURGE, $user->featureKeys());
        $this->assertFalse($user->canAccessFeature(Features::NEWS_SURGE));

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->json('checkout.id');

        $this->authed($user)->postJson("/api/billing/checkouts/{$checkoutId}/confirm")->assertOk();

        $me = $this->authed($user)->getJson('/api/auth/me')->assertOk();

        $me->assertJsonPath('user.subscription_id', $pro->id);

        $this->assertContains(Features::NEWS_SURGE, $me->json('features'));

        $user->refresh();
        $this->assertTrue($user->canAccessFeature(Features::NEWS_SURGE));
        $this->assertContains(Features::NEWS_SURGE, $user->featureKeys());
    }

    public function test_a_declined_payment_does_not_grant_the_plan(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
        {
            public function name(): string
            {
                return 'declining';
            }

            public function collectsPayment(): bool
            {
                return true;
            }

            public function createIntent(SubscriptionCheckout $checkout): array
            {
                return [];
            }

            public function confirm(SubscriptionCheckout $checkout): bool
            {
                return false;
            }

            public function failureReason(SubscriptionCheckout $checkout): ?string
            {
                return 'Card declined.';
            }

            public function sync(User $user, SubscriptionCheckout $checkout): void {}
        });

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->json('checkout.id');

        $this->authed($user)
            ->postJson("/api/billing/checkouts/{$checkoutId}/confirm")
            ->assertStatus(402)
            ->assertJsonPath('checkout.status', SubscriptionCheckout::STATUS_FAILED);

        $this->assertSame($free->id, $user->fresh()->subscription_id);
    }

    public function test_manual_confirm_is_blocked_when_disallowed(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        config(['billing.allow_manual' => false]);

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->json('checkout.id');

        // The guard is a thrown exception, so let it escape the HTTP handler.
        $this->withoutExceptionHandling();

        try {
            $this->authed($user)->postJson("/api/billing/checkouts/{$checkoutId}/confirm");
            $this->fail('Expected manual confirmation to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Manual confirmations are disabled', $e->getMessage());
        } finally {
            // Even after the throw the plan must not have moved.
            $this->assertSame($free->id, $user->fresh()->subscription_id);
            $this->assertSame(
                SubscriptionCheckout::STATUS_PENDING,
                $user->checkouts()->latest('id')->value('status'),
            );
        }
    }

    public function test_users_cannot_settle_another_users_checkout(): void
    {
        $pro = $this->plan('Pro', 29, 290);
        $victim = $this->user(null, ['email' => 'victim@example.com']);
        $attacker = $this->user(null, ['email' => 'attacker@example.com']);

        $checkoutId = $this->authed($victim)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->json('checkout.id');

        $this->authed($attacker)
            ->postJson("/api/billing/checkouts/{$checkoutId}/confirm")
            ->assertStatus(403);

        $this->assertNull($victim->fresh()->subscription_id);
    }

    public function test_admins_cannot_start_a_checkout(): void
    {
        $pro = $this->plan('Pro', 29, 290);
        $admin = $this->user(null, ['email' => 'admin@example.com', 'role' => User::ROLE_ADMIN]);

        $this->authed($admin)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->assertStatus(422);
    }

    public function test_inactive_plans_cannot_be_bought(): void
    {
        $retired = $this->plan('Retired', 10);
        $retired->update(['is_active' => false]);
        $user = $this->user();

        $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $retired->id,
            'billing_period' => 'monthly',
        ])->assertStatus(404);
    }

    public function test_a_second_pending_checkout_replaces_the_first(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->assertCreated();

        $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'annual',
        ])->assertCreated();

        $this->assertSame(1, $user->checkouts()->where('status', SubscriptionCheckout::STATUS_PENDING)->count());
        $this->assertSame(1, $user->checkouts()->count());
    }

    public function test_cancelling_a_pending_checkout_leaves_the_plan_alone(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->json('checkout.id');

        $this->authed($user)
            ->postJson("/api/billing/checkouts/{$checkoutId}/cancel")
            ->assertOk()
            ->assertJsonPath('checkout.status', SubscriptionCheckout::STATUS_CANCELLED);

        $this->assertSame($free->id, $user->fresh()->subscription_id);
    }

    public function test_confirming_twice_is_idempotent(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free);

        $checkoutId = $this->authed($user)->postJson('/api/billing/checkouts', [
            'subscription_id' => $pro->id,
            'billing_period' => 'monthly',
        ])->json('checkout.id');

        $this->authed($user)->postJson("/api/billing/checkouts/{$checkoutId}/confirm")->assertOk();
        $this->authed($user)->postJson("/api/billing/checkouts/{$checkoutId}/confirm")->assertOk();

        $this->assertSame(1, $user->checkouts()->where('status', SubscriptionCheckout::STATUS_PAID)->count());
    }

    public function test_billing_endpoint_reports_current_state(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $user = $this->user($free);

        $res = $this->authed($user)->getJson('/api/billing/subscription')->assertOk();

        $res->assertJsonPath('subscription.id', $free->id)
            ->assertJsonPath('auto_renew', false)
            ->assertJsonPath('is_expired', false)
            ->assertJsonPath('payment_collected', false);
    }
}
