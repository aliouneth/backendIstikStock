<?php

namespace App\Billing\Stripe;

use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Applies verified Stripe webhook events to the local subscription state.
 *
 * Stripe owns recurring billing, so it is the source of truth for renewals,
 * failures and cancellations. These handlers mirror those events onto the user
 * (plan, expiry, auto-renew) and the checkout history.
 */
class StripeSubscriptionSyncer
{
    public function __construct(private readonly StripeClientContract $stripe) {}

    /**
     * @param  array<string, mixed>  $object
     */
    public function handle(string $type, array $object): void
    {
        match ($type) {
            'invoice.paid', 'invoice.payment_succeeded' => $this->invoicePaid($object),
            'invoice.payment_failed' => $this->invoicePaymentFailed($object),
            'customer.subscription.updated' => $this->subscriptionUpdated($object),
            'customer.subscription.deleted' => $this->subscriptionDeleted($object),
            default => null,
        };
    }

    /**
     * A paid (or renewed) invoice: extend access to the new period end and make
     * sure the user is on the purchased plan even if the browser never came
     * back from a 3-D Secure redirect.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function invoicePaid(array $invoice): void
    {
        $subscription = $this->retrieveSubscription($invoice);

        $user = $this->resolveUser($subscription ?? $invoice);

        if (! $user) {
            Log::warning('Stripe invoice.paid could not be matched to a user.', [
                'customer' => $invoice['customer'] ?? null,
            ]);

            return;
        }

        $subscriptionId = $this->invoiceSubscriptionId($invoice) ?? $user->stripe_subscription_id;
        $planId = $subscription['metadata']['subscription_id'] ?? null;
        $endsAt = $this->invoicePeriodEnd($invoice);

        $attributes = [
            'auto_renew' => true,
            'stripe_subscription_id' => $subscriptionId,
        ];

        if ($planId && Subscription::query()->whereKey($planId)->exists()) {
            $attributes['subscription_id'] = (int) $planId;
        }

        if ($endsAt) {
            $attributes['subscription_expires_at'] = $endsAt;
        }

        $user->forceFill($attributes)->save();

        $this->markCheckoutsPaid($user, $subscriptionId, $endsAt);

        Entitlements::flushCache();
    }

    /**
     * A failed renewal. Access is intentionally left untouched here so the user
     * keeps the term they already paid for while Stripe retries the charge.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function invoicePaymentFailed(array $invoice): void
    {
        Log::warning('Stripe invoice.payment_failed.', [
            'customer' => $invoice['customer'] ?? null,
            'subscription' => $this->invoiceSubscriptionId($invoice),
            'reason' => $invoice['last_payment_error']['message'] ?? $invoice['failure_reason'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionUpdated(array $subscription): void
    {
        $user = $this->resolveUser($subscription);

        if (! $user) {
            return;
        }

        $status = $subscription['status'] ?? null;
        $attributes = [];

        if (in_array($status, ['active', 'trialing'], true)) {
            $attributes['auto_renew'] = true;

            if ($endsAt = $this->subscriptionPeriodEnd($subscription)) {
                $attributes['subscription_expires_at'] = $endsAt;
            }
        } elseif ($status === 'canceled') {
            $attributes['auto_renew'] = false;
        }

        if ($attributes) {
            $user->forceFill($attributes)->save();
            Entitlements::flushCache();
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionDeleted(array $subscription): void
    {
        $user = $this->resolveUser($subscription);

        if (! $user) {
            return;
        }

        // Stop renewing; the existing expiry (the paid-for period) is left in
        // place so access lapses naturally instead of being cut off instantly.
        $user->forceFill([
            'auto_renew' => false,
            'stripe_subscription_id' => null,
        ])->save();

        Entitlements::flushCache();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function resolveUser(array $object): ?User
    {
        $userId = $object['metadata']['user_id'] ?? null;

        if ($userId && $user = User::query()->find($userId)) {
            return $user;
        }

        $customerId = $object['customer'] ?? null;

        if (is_string($customerId) && $customerId !== '') {
            return User::query()->where('stripe_customer_id', $customerId)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>|null
     */
    private function retrieveSubscription(array $invoice): ?array
    {
        $subscriptionId = $this->invoiceSubscriptionId($invoice);

        if (! $subscriptionId) {
            return null;
        }

        try {
            return $this->stripe->retrieveSubscription($subscriptionId);
        } catch (\Throwable $e) {
            Log::warning('Stripe subscription lookup failed during webhook.', [
                'subscription' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function markCheckoutsPaid(User $user, ?string $subscriptionId, ?CarbonImmutable $endsAt): void
    {
        $query = $user->checkouts()->where('status', SubscriptionCheckout::STATUS_PENDING);

        if ($subscriptionId) {
            $query->where(function ($inner) use ($subscriptionId) {
                $inner->where('stripe_subscription_id', $subscriptionId)
                    ->orWhereNull('stripe_subscription_id');
            });
        }

        $query->get()->each(function (SubscriptionCheckout $checkout) use ($endsAt) {
            $checkout->forceFill([
                'status' => SubscriptionCheckout::STATUS_PAID,
                'paid_at' => $checkout->paid_at ?? now(),
                'period_ends_at' => $endsAt,
                'failure_reason' => null,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function invoiceSubscriptionId(array $invoice): ?string
    {
        $id = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function invoicePeriodEnd(array $invoice): ?CarbonImmutable
    {
        $timestamp = $invoice['lines']['data'][0]['period']['end']
            ?? $invoice['period_end']
            ?? null;

        return $timestamp ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionPeriodEnd(array $subscription): ?CarbonImmutable
    {
        $timestamp = $subscription['current_period_end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? null;

        return $timestamp ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }
}
