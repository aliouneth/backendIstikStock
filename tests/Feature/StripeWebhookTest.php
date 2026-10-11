<?php

namespace Tests\Feature;

use App\Billing\Stripe\StripeClientContract;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeClient;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => $this->secret]);
    }

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
            'name' => 'Buyer',
            'email' => $overrides['email'] ?? uniqid('buyer', true).'@example.com',
            'password' => 'Password123!',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'subscription_id' => $plan?->id,
            'stripe_customer_id' => $overrides['stripe_customer_id'] ?? null,
            'auto_renew' => $overrides['auto_renew'] ?? false,
            'stripe_subscription_id' => $overrides['stripe_subscription_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postWebhook(array $event, ?string $secret = null): TestResponse
    {
        $payload = json_encode($event);
        $header = WebhookSignature::generateSignatureHeader($payload, $secret ?? $this->secret);

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $header],
            $payload,
        );
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $payload = json_encode(['id' => 'evt_bad', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_bad']]]);

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef'],
            $payload,
        )->assertStatus(400);

        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_missing_webhook_secret_disables_the_endpoint(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $this->postWebhook(['id' => 'evt_1', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1']]])
            ->assertStatus(500);
    }

    public function test_invoice_paid_grants_and_extends_the_plan(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free, ['stripe_customer_id' => 'cus_123']);

        $periodEnd = 1893456000; // 2030-01-01

        $fake = new FakeStripeClient;
        $fake->subscriptions['sub_123'] = [
            'id' => 'sub_123',
            'current_period_end' => $periodEnd,
            'metadata' => ['user_id' => (string) $user->id, 'subscription_id' => (string) $pro->id],
        ];
        $this->app->instance(StripeClientContract::class, $fake);

        $this->postWebhook([
            'id' => 'evt_paid_1',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_1',
                'customer' => 'cus_123',
                'subscription' => 'sub_123',
                'lines' => ['data' => [['period' => ['end' => $periodEnd]]]],
            ]],
        ])->assertOk();

        $user->refresh();
        $this->assertSame($pro->id, $user->subscription_id);
        $this->assertTrue($user->auto_renew);
        $this->assertSame('sub_123', $user->stripe_subscription_id);
        $this->assertSame($periodEnd, $user->subscription_expires_at->timestamp);

        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_paid_1',
            'type' => 'invoice.paid',
        ]);
    }

    public function test_invoice_paid_marks_the_pending_checkout_as_paid(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($free, ['stripe_customer_id' => 'cus_123']);

        $checkout = $user->checkouts()->create([
            'subscription_id' => $pro->id,
            'plan_name' => $pro->name,
            'billing_period' => 'monthly',
            'amount' => 29,
            'currency' => 'usd',
            'auto_renew' => true,
            'status' => SubscriptionCheckout::STATUS_PENDING,
            'driver' => 'stripe',
            'stripe_subscription_id' => 'sub_123',
        ]);

        $fake = new FakeStripeClient;
        $fake->subscriptions['sub_123'] = [
            'id' => 'sub_123',
            'metadata' => ['user_id' => (string) $user->id, 'subscription_id' => (string) $pro->id],
        ];
        $this->app->instance(StripeClientContract::class, $fake);

        $this->postWebhook([
            'id' => 'evt_paid_2',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_2',
                'customer' => 'cus_123',
                'subscription' => 'sub_123',
                'lines' => ['data' => [['period' => ['end' => 1893456000]]]],
            ]],
        ])->assertOk();

        $this->assertSame(SubscriptionCheckout::STATUS_PAID, $checkout->fresh()->status);
        $this->assertNotNull($checkout->fresh()->paid_at);
    }

    public function test_duplicate_events_are_only_processed_once(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $user = $this->user($free, ['stripe_customer_id' => 'cus_123']);

        $fake = new FakeStripeClient;
        $fake->subscriptions['sub_123'] = ['id' => 'sub_123', 'metadata' => ['user_id' => (string) $user->id]];
        $this->app->instance(StripeClientContract::class, $fake);

        $event = [
            'id' => 'evt_dup_1',
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_3', 'customer' => 'cus_123', 'subscription' => 'sub_123']],
        ];

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk();

        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertSame(1, $fake->called('retrieveSubscription'));
    }

    public function test_subscription_deleted_disables_auto_renew(): void
    {
        $pro = $this->plan('Pro', 29, 290);
        $user = $this->user($pro, [
            'stripe_customer_id' => 'cus_123',
            'auto_renew' => true,
            'stripe_subscription_id' => 'sub_123',
        ]);

        $this->app->instance(StripeClientContract::class, new FakeStripeClient);

        $this->postWebhook([
            'id' => 'evt_del_1',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => [
                'id' => 'sub_123',
                'customer' => 'cus_123',
                'status' => 'canceled',
                'metadata' => ['user_id' => (string) $user->id],
            ]],
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->auto_renew);
        $this->assertNull($user->stripe_subscription_id);
    }

    public function test_paid_invoice_falls_back_to_the_customer_when_metadata_is_absent(): void
    {
        $free = $this->plan('Free', 0, null, true);
        $user = $this->user($free, ['stripe_customer_id' => 'cus_only']);

        $fake = new FakeStripeClient;
        // The subscription cannot be retrieved, so the customer id on the
        // invoice is the only way to match the account.
        $this->app->instance(StripeClientContract::class, $fake);

        $this->postWebhook([
            'id' => 'evt_cust_1',
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_4',
                'customer' => 'cus_only',
                'lines' => ['data' => [['period' => ['end' => 1893456000]]]],
            ]],
        ])->assertOk();

        $this->assertTrue($user->fresh()->auto_renew);
        $this->assertSame(1893456000, $user->fresh()->subscription_expires_at->timestamp);
    }
}
