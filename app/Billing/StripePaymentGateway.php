<?php

namespace App\Billing;

use App\Billing\Stripe\StripeClientContract;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Stripe driver.
 *
 * Recurring checkouts (auto_renew) become Stripe Billing subscriptions whose
 * first invoice is settled inline through the Payment Element. One-off checkouts
 * become a plain PaymentIntent. Either way the front end receives a
 * client_secret it hands to Stripe.js, and the plan is only granted once the
 * payment really succeeded.
 */
class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClientContract $stripe) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function collectsPayment(): bool
    {
        return true;
    }

    public function createIntent(SubscriptionCheckout $checkout): array
    {
        // Re-opening the pay step for an in-flight checkout must not create a
        // second charge: reuse the payment intent we already started.
        if ($checkout->provider_reference) {
            $intent = $this->stripe->retrievePaymentIntent($checkout->provider_reference);

            return $this->paymentPayload($intent['client_secret'] ?? null, $checkout);
        }

        $customerId = $this->customerId($checkout->user);

        return $checkout->auto_renew
            ? $this->startSubscription($checkout, $customerId)
            : $this->startOneOffPayment($checkout, $customerId);
    }

    public function confirm(SubscriptionCheckout $checkout): bool
    {
        if (! $checkout->provider_reference) {
            return false;
        }

        $intent = $this->stripe->retrievePaymentIntent($checkout->provider_reference);

        return ($intent['status'] ?? null) === 'succeeded';
    }

    public function failureReason(SubscriptionCheckout $checkout): ?string
    {
        if (! $checkout->provider_reference) {
            return 'Payment was not started.';
        }

        $intent = $this->stripe->retrievePaymentIntent($checkout->provider_reference);

        return $intent['last_payment_error']['message'] ?? 'Payment was not completed.';
    }

    /**
     * Persist the Stripe identifiers after a successful checkout and bound the
     * local expiry to the Stripe billing period. The renewal webhook is what
     * pushes it forward on each subsequent invoice.
     */
    public function sync(User $user, SubscriptionCheckout $checkout): void
    {
        if (! $checkout->stripe_subscription_id) {
            // One-off payment: CheckoutService already stamped the local expiry.
            return;
        }

        $subscription = $this->stripe->retrieveSubscription($checkout->stripe_subscription_id);

        $user->forceFill([
            'stripe_subscription_id' => $subscription['id'] ?? $checkout->stripe_subscription_id,
            'subscription_expires_at' => $this->periodEnd($subscription)
                ?? Pricing::periodEndsAt($checkout->billing_period),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function startSubscription(SubscriptionCheckout $checkout, string $customerId): array
    {
        $subscription = $this->stripe->createSubscription([
            'customer' => $customerId,
            'items' => [[
                'price_data' => [
                    'currency' => $checkout->currency,
                    'product' => $this->productFor($checkout->subscription),
                    'unit_amount' => $this->minorUnits($checkout->amount),
                    'recurring' => ['interval' => $this->interval($checkout->billing_period)],
                ],
            ]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => $this->metadata($checkout),
        ]);

        $intent = $subscription['latest_invoice']['payment_intent'] ?? null;
        $clientSecret = is_array($intent) ? ($intent['client_secret'] ?? null) : null;
        $intentId = is_array($intent) ? ($intent['id'] ?? null) : null;

        if ($clientSecret === null && ! empty($subscription['latest_invoice']['id'])) {
            $invoiceId = $subscription['latest_invoice']['id'];
            $invoice = $this->stripe->retrieveInvoice($invoiceId, ['expand' => ['payment_intent']]);
            $intent = $invoice['payment_intent'] ?? null;
            $clientSecret = is_array($intent) ? ($intent['client_secret'] ?? null) : null;
            $intentId = is_array($intent) ? ($intent['id'] ?? null) : null;
            if ($clientSecret === null && $invoiceId) {
                $pi = $this->stripe->createPaymentIntent([
                    'amount' => $invoice['amount_due'] ?? $checkout->amount,
                    'currency' => $invoice['currency'] ?? $checkout->currency,
                    'customer' => $customerId,
                    'automatic_payment_methods' => ['enabled' => true],
                    'metadata' => $this->metadata($checkout) + ['invoice_id' => $invoiceId, 'subscription_id' => $subscription['id'] ?? null],
                ]);
                $clientSecret = $pi['client_secret'] ?? null;
                $intentId = $pi['id'] ?? null;
            }
        }

        if ($clientSecret === null) {
            throw new RuntimeException('Stripe did not return a payment intent for this subscription.');
        }

        $checkout->forceFill([
            'provider_reference' => $intentId,
            'stripe_subscription_id' => $subscription['id'] ?? null,
        ])->save();

        return $this->paymentPayload($clientSecret, $checkout);
    }

    /**
     * @return array<string, mixed>
     */
    private function startOneOffPayment(SubscriptionCheckout $checkout, string $customerId): array
    {
        $intent = $this->stripe->createPaymentIntent([
            'amount' => $this->minorUnits($checkout->amount),
            'currency' => $checkout->currency,
            'customer' => $customerId,
            'automatic_payment_methods' => ['enabled' => true],
            'description' => $checkout->plan_name.' ('.$checkout->billing_period.')',
            'metadata' => $this->metadata($checkout),
        ]);

        $checkout->forceFill(['provider_reference' => $intent['id'] ?? null])->save();

        return $this->paymentPayload($intent['client_secret'] ?? null, $checkout);
    }

    /**
     * Resolve (creating once) the Stripe customer that owns this user's cards.
     */
    private function customerId(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe->createCustomer([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->forceFill(['stripe_customer_id' => $customer['id']])->save();

        return $customer['id'];
    }

    /**
     * Resolve (creating once) the Stripe product that represents this plan.
     */
    private function productFor(Subscription $plan): string
    {
        if ($plan->stripe_product_id) {
            return $plan->stripe_product_id;
        }

        $product = $this->stripe->createProduct([
            'name' => $plan->name,
            'metadata' => ['subscription_id' => $plan->id],
        ]);

        $plan->forceFill(['stripe_product_id' => $product['id']])->save();

        return $product['id'];
    }

    /**
     * @return array<string, string>
     */
    private function metadata(SubscriptionCheckout $checkout): array
    {
        return [
            'user_id' => (string) $checkout->user_id,
            'subscription_id' => (string) $checkout->subscription_id,
            'checkout_id' => (string) $checkout->id,
            'billing_period' => $checkout->billing_period,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(?string $clientSecret, SubscriptionCheckout $checkout): array
    {
        return [
            'mode' => 'stripe',
            'collects_payment' => true,
            'client_secret' => $clientSecret,
            'publishable_key' => config('services.stripe.key'),
            'recurring' => (bool) $checkout->auto_renew,
            'label' => 'Card payment',
        ];
    }

    private function interval(string $period): string
    {
        return $period === Pricing::ANNUAL ? 'year' : 'month';
    }

    private function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function periodEnd(array $subscription): ?CarbonImmutable
    {
        $timestamp = $subscription['current_period_end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? null;

        return $timestamp ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }
}
