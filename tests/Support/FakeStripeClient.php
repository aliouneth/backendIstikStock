<?php

namespace Tests\Support;

use App\Billing\Stripe\StripeClientContract;

/**
 * In-memory stand-in for the Stripe SDK used by the billing tests.
 *
 * Records every call and serves canned responses so the gateway and webhook
 * syncer can be tested without touching the network.
 */
class FakeStripeClient implements StripeClientContract
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $createSubscriptionResponse = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<string, array<string, mixed>> */
    public array $paymentIntents = [];

    private int $customerCounter = 0;

    private int $productCounter = 0;

    public function createCustomer(array $params): array
    {
        $this->calls[] = ['createCustomer', $params];

        return ['id' => 'cus_test_'.(++$this->customerCounter)];
    }

    public function createProduct(array $params): array
    {
        $this->calls[] = ['createProduct', $params];

        return ['id' => 'prod_test_'.(++$this->productCounter)];
    }

    public function createSubscription(array $params): array
    {
        $this->calls[] = ['createSubscription', $params];

        $response = $this->createSubscriptionResponse ?: [
            'id' => 'sub_test_1',
            'latest_invoice' => [
                'payment_intent' => [
                    'id' => 'pi_sub_default',
                    'client_secret' => 'pi_sub_default_secret',
                    'status' => 'requires_payment_method',
                ],
            ],
        ];

        if (isset($response['id'])) {
            $this->subscriptions[$response['id']] = $response;
        }

        return $response;
    }

    public function retrieveSubscription(string $id, array $params = []): array
    {
        $this->calls[] = ['retrieveSubscription', ['id' => $id, 'params' => $params]];

        return $this->subscriptions[$id] ?? ['id' => $id];
    }

    public function cancelSubscription(string $id): array
    {
        $this->calls[] = ['cancelSubscription', ['id' => $id]];

        return $this->subscriptions[$id] ?? ['id' => $id];
    }

    public function createPaymentIntent(array $params): array
    {
        $this->calls[] = ['createPaymentIntent', $params];

        $id = 'pi_oneoff_'.(count($this->paymentIntents) + 1);

        return $this->paymentIntents[$id] = [
            'id' => $id,
            'client_secret' => $id.'_secret',
            'status' => 'requires_payment_method',
        ];
    }

    public function retrievePaymentIntent(string $id): array
    {
        $this->calls[] = ['retrievePaymentIntent', ['id' => $id]];

        return $this->paymentIntents[$id] ?? ['id' => $id, 'status' => 'requires_payment_method'];
    }

    public function called(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call[0] === $method));
    }
}
