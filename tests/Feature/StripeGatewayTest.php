<?php

namespace Tests\Feature;

use App\Billing\CheckoutService;
use App\Billing\Pricing;
use App\Billing\StripePaymentGateway;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Features;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeClient;
use Tests\TestCase;

class StripeGatewayTest extends TestCase
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

    private function user(?Subscription $plan = null): User
    {
        return User::query()->create([
            'name' => 'Buyer',
            'email' => uniqid('buyer', true).'@example.com',
            'password' => 'Password123!',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'subscription_id' => $plan?->id,
        ]);
    }

    public function test_recurring_checkout_starts_a_stripe_subscription(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $fake = new FakeStripeClient;
        $fake->createSubscriptionResponse = [
            'id' => 'sub_1',
            'latest_invoice' => [
                'payment_intent' => ['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'status' => 'requires_payment_method'],
            ],
        ];

        $service = new CheckoutService(new StripePaymentGateway($fake));
        $checkout = $service->open($user, $plan, Pricing::MONTHLY, true);
        $payload = $service->paymentIntent($checkout);

        $this->assertSame('stripe', $payload['mode']);
        $this->assertTrue($payload['collects_payment']);
        $this->assertSame('pi_1_secret', $payload['client_secret']);

        $checkout->refresh();
        $this->assertSame('pi_1', $checkout->provider_reference);
        $this->assertSame('sub_1', $checkout->stripe_subscription_id);
        $this->assertSame('cus_test_1', $user->fresh()->stripe_customer_id);

        $call = collect($fake->calls)->firstWhere(0, 'createSubscription');
        $this->assertSame('month', $call[1]['items'][0]['price_data']['recurring']['interval']);
        $this->assertSame(2900, $call[1]['items'][0]['price_data']['unit_amount']);
        $this->assertSame('monthly', $call[1]['metadata']['billing_period']);
        $this->assertSame((string) $user->id, $call[1]['metadata']['user_id']);
    }

    public function test_annual_recurring_checkout_uses_a_yearly_interval(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $fake = new FakeStripeClient;
        $fake->createSubscriptionResponse = [
            'id' => 'sub_annual',
            'latest_invoice' => [
                'payment_intent' => ['id' => 'pi_a', 'client_secret' => 'pi_a_secret', 'status' => 'requires_payment_method'],
            ],
        ];

        $service = new CheckoutService(new StripePaymentGateway($fake));
        $service->paymentIntent($service->open($user, $plan, Pricing::ANNUAL, true));

        $call = collect($fake->calls)->firstWhere(0, 'createSubscription');
        $this->assertSame('year', $call[1]['items'][0]['price_data']['recurring']['interval']);
        $this->assertSame(29000, $call[1]['items'][0]['price_data']['unit_amount']);
    }

    public function test_plan_product_is_created_once_and_cached(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $fake = new FakeStripeClient;
        $fake->createSubscriptionResponse = [
            'id' => 'sub_1',
            'latest_invoice' => [
                'payment_intent' => ['id' => 'pi_1', 'client_secret' => 'pi_1_secret', 'status' => 'requires_payment_method'],
            ],
        ];

        $service = new CheckoutService(new StripePaymentGateway($fake));
        $service->paymentIntent($service->open($user, $plan, Pricing::MONTHLY, true));
        $service->paymentIntent($service->open($user, $plan, Pricing::ANNUAL, true));

        $this->assertSame('prod_test_1', $plan->fresh()->stripe_product_id);
        $this->assertSame(1, $fake->called('createProduct'));
        $this->assertSame(2, $fake->called('createSubscription'));
    }

    public function test_one_off_checkout_creates_a_payment_intent_not_a_subscription(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $fake = new FakeStripeClient;
        $service = new CheckoutService(new StripePaymentGateway($fake));

        $checkout = $service->open($user, $plan, Pricing::ANNUAL, false);
        $payload = $service->paymentIntent($checkout);

        $this->assertSame('stripe', $payload['mode']);
        $this->assertSame(0, $fake->called('createSubscription'));

        $checkout->refresh();
        $this->assertNull($checkout->stripe_subscription_id);
        $this->assertSame('pi_oneoff_1', $checkout->provider_reference);

        $call = collect($fake->calls)->firstWhere(0, 'createPaymentIntent');
        $this->assertSame(29000, $call[1]['amount']);
        $this->assertSame('usd', $call[1]['currency']);
    }

    public function test_confirm_only_succeeds_once_the_intent_is_paid(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $fake = new FakeStripeClient;
        $service = new CheckoutService(new StripePaymentGateway($fake));

        $checkout = $service->open($user, $plan, Pricing::MONTHLY, false);
        $service->paymentIntent($checkout);

        $gateway = new StripePaymentGateway($fake);
        $this->assertFalse($gateway->confirm($checkout->refresh()));

        $fake->paymentIntents['pi_oneoff_1']['status'] = 'succeeded';
        $this->assertTrue($gateway->confirm($checkout));
    }

    public function test_reopening_the_pay_step_reuses_the_same_intent(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $fake = new FakeStripeClient;
        $service = new CheckoutService(new StripePaymentGateway($fake));

        $checkout = $service->open($user, $plan, Pricing::MONTHLY, false);
        $service->paymentIntent($checkout);
        $service->paymentIntent($checkout->refresh());

        $this->assertSame(1, $fake->called('createPaymentIntent'));
        $this->assertSame(1, $fake->called('retrievePaymentIntent'));
    }

    public function test_settle_syncs_stripe_subscription_and_expiry(): void
    {
        $plan = $this->plan('Pro', 29, 290);
        $user = $this->user();

        $periodEnd = CarbonImmutable::createFromTimestamp(1893456000); // 2030-01-01

        $fake = new FakeStripeClient;
        $fake->createSubscriptionResponse = [
            'id' => 'sub_9',
            'current_period_end' => $periodEnd->timestamp,
            'latest_invoice' => [
                'payment_intent' => ['id' => 'pi_9', 'client_secret' => 'pi_9_secret', 'status' => 'requires_payment_method'],
            ],
        ];

        $service = new CheckoutService(new StripePaymentGateway($fake));
        $checkout = $service->open($user, $plan, Pricing::ANNUAL, true);
        $service->paymentIntent($checkout);

        $fake->paymentIntents['pi_9']['status'] = 'succeeded';
        $service->settle($checkout->refresh());

        $user->refresh();
        $this->assertSame($plan->id, $user->subscription_id);
        $this->assertTrue($user->auto_renew);
        $this->assertSame('sub_9', $user->stripe_subscription_id);
        $this->assertSame($periodEnd->timestamp, $user->subscription_expires_at->timestamp);
    }
}
