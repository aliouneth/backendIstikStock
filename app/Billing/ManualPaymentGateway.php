<?php

namespace App\Billing;

use App\Models\SubscriptionCheckout;
use App\Models\User;
use RuntimeException;

/**
 * Placeholder driver used before a real processor is wired up.
 *
 * It never charges anything. The front end labels it clearly as "no payment
 * collected" for that reason. It refuses to settle at all in production so a
 * leftover BILLING_DRIVER=manual can never hand out unpaid upgrades.
 */
class ManualPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function collectsPayment(): bool
    {
        return false;
    }

    public function createIntent(SubscriptionCheckout $checkout): array
    {
        return [
            'mode' => 'manual',
            'collects_payment' => false,
            'label' => 'No payment processor configured',
            'message' => 'This order will not be charged. Once Stripe is enabled the same checkout flow captures payment and then grants the plan.',
        ];
    }

    public function confirm(SubscriptionCheckout $checkout): bool
    {
        if (! config('billing.allow_manual')) {
            throw new RuntimeException('Manual confirmations are disabled. Set a real payment driver or BILLING_ALLOW_MANUAL=true for local testing.');
        }

        return true;
    }

    public function failureReason(SubscriptionCheckout $checkout): ?string
    {
        return 'Manual confirmation is disabled on this environment.';
    }

    public function sync(User $user, SubscriptionCheckout $checkout): void
    {
        // The manual driver has no external billing record to reconcile.
    }
}
