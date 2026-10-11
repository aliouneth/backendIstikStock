<?php

namespace App\Billing\Stripe;

use Stripe\StripeClient;

/**
 * Real Stripe implementation of {@see StripeClientContract}.
 *
 * Wraps the official SDK and normalises every result to an array so the rest of
 * the billing layer never has to know about Stripe's object graph.
 */
class SdkStripeClient implements StripeClientContract
{
    public function __construct(private readonly StripeClient $client) {}

    public function createCustomer(array $params): array
    {
        return $this->client->customers->create($params)->toArray();
    }

    public function createProduct(array $params): array
    {
        return $this->client->products->create($params)->toArray();
    }

    public function createSubscription(array $params): array
    {
        return $this->client->subscriptions->create($params)->toArray();
    }

    public function retrieveSubscription(string $id, array $params = []): array
    {
        return $this->client->subscriptions->retrieve($id, $params)->toArray();
    }

    public function cancelSubscription(string $id): array
    {
        return $this->client->subscriptions->cancel($id)->toArray();
    }

    public function createPaymentIntent(array $params): array
    {
        return $this->client->paymentIntents->create($params)->toArray();
    }

    public function retrievePaymentIntent(string $id, array $params = []): array
    {
        return $this->client->paymentIntents->retrieve($id, $params)->toArray();
    }

    public function retrieveInvoice(string $id, array $params = []): array
    {
        return $this->client->invoices->retrieve($id, $params)->toArray();
    }

    public function createInvoicePaymentIntent(string $invoiceId, array $params = []): array
    {
        return $this->client->invoices->createPaymentIntent($invoiceId, $params)->toArray();
    }
}
