<?php

namespace App\Billing\Stripe;

/**
 * Thin seam over the Stripe SDK.
 *
 * Everything the billing layer needs from Stripe goes through this interface so
 * the gateway can be exercised in tests with a fake instead of the network.
 * Every method returns a plain array (already converted from the SDK objects).
 */
interface StripeClientContract
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createCustomer(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createProduct(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createSubscription(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $id, array $params = []): array;

    /**
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $id): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createPaymentIntent(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function retrievePaymentIntent(string $id, array $params = []): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function retrieveInvoice(string $id, array $params = []): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createInvoicePaymentIntent(string $invoiceId, array $params = []): array;
}
