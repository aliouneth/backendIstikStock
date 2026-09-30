<?php

namespace App\Billing;

use App\Models\SubscriptionCheckout;

interface PaymentGateway
{
    /**
     * Short driver name recorded on the checkout row ("manual", "stripe", ...).
     */
    public function name(): string;

    /**
     * False when the driver cannot actually move money. Used to keep the
     * "no payment collected" UI honest instead of faking a receipt.
     */
    public function collectsPayment(): bool;

    /**
     * Build whatever the front end needs to render the pay step (Stripe would
     * return a client secret / publishable key pair; manual returns nothing).
     *
     * @return array<string, mixed>
     */
    public function createIntent(SubscriptionCheckout $checkout): array;

    /**
     * Settle the checkout. MUST only return true once the money is actually
     * captured (or a verified webhook has confirmed it) - the caller grants
     * the plan on a true result, so a false positive is a free upgrade.
     */
    public function confirm(SubscriptionCheckout $checkout): bool;

    /**
     * Human readable reason a confirm() returned false.
     */
    public function failureReason(SubscriptionCheckout $checkout): ?string;
}
