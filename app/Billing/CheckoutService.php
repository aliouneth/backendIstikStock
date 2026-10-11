<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use App\Models\User;
use App\Support\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CheckoutService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Open a pending checkout. The plan is recorded but NOT granted here - only
     * after the gateway reports captured payment.
     */
    public function open(User $user, Subscription $plan, string $period, bool $autoRenew): SubscriptionCheckout
    {
        if (! Pricing::isValidPeriod($period)) {
            throw new InvalidArgumentException('Unsupported billing period.');
        }

        if (! $plan->is_active) {
            throw new InvalidArgumentException('That plan is not available.');
        }

        // Never stack checkouts: a user may only have one open at a time.
        $open = $user->checkouts()->where('status', SubscriptionCheckout::STATUS_PENDING)->first();

        if ($open) {
            $open->delete();
        }

        $checkout = $user->checkouts()->create([
            'subscription_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_period' => $period,
            'amount' => Pricing::amountFor($plan, $period),
            'currency' => config('billing.currency', 'usd'),
            'auto_renew' => $autoRenew,
            'status' => SubscriptionCheckout::STATUS_PENDING,
            'driver' => $this->gateway->name(),
        ]);

        $checkout->setRelation('subscription', $plan);

        return $checkout;
    }

    /**
     * Driver specific payload the front end needs to render the pay step.
     *
     * @return array<string, mixed>
     */
    public function paymentIntent(SubscriptionCheckout $checkout): array
    {
        return $this->gateway->createIntent($checkout);
    }

    public function driverCollectsPayment(): bool
    {
        return $this->gateway->collectsPayment();
    }

    /**
     * Settle the checkout and, only if the driver really took the money, move
     * the user onto the purchased plan.
     */
    public function settle(SubscriptionCheckout $checkout): SubscriptionCheckout
    {
        if ($checkout->isPaid()) {
            return $checkout;
        }

        if ($checkout->status !== SubscriptionCheckout::STATUS_PENDING) {
            throw new InvalidArgumentException('That checkout is no longer pending.');
        }

        if (! $this->gateway->confirm($checkout)) {
            $checkout->forceFill([
                'status' => SubscriptionCheckout::STATUS_FAILED,
                'failure_reason' => $this->gateway->failureReason($checkout),
            ])->save();

            return $checkout;
        }

        return DB::transaction(function () use ($checkout) {
            $checkout->forceFill([
                'status' => SubscriptionCheckout::STATUS_PAID,
                'paid_at' => now(),
                'failure_reason' => null,
            ])->save();

            $this->grant($checkout);

            return $checkout->fresh();
        });
    }

    private function grant(SubscriptionCheckout $checkout): void
    {
        $user = $checkout->user;
        $endsAt = Pricing::periodEndsAt($checkout->billing_period, CarbonImmutable::now());

        $checkout->forceFill(['period_ends_at' => $endsAt])->save();

        $user->forceFill([
            'subscription_id' => $checkout->subscription_id,
            'auto_renew' => $checkout->auto_renew,
            // With auto-renew on we leave the expiry empty: access stays live and
            // the future Stripe webhook / renewal job is what extends it. With
            // renew off we set it so access lapses at the end of the paid term.
            'subscription_expires_at' => $checkout->auto_renew ? null : $endsAt,
        ])->save();

        // Let the driver attach its own billing record (Stripe customer /
        // subscription ids and the provider-side period end).
        $this->gateway->sync($user, $checkout);

        Entitlements::flushCache();
    }

    public function cancel(SubscriptionCheckout $checkout): SubscriptionCheckout
    {
        if ($checkout->status === SubscriptionCheckout::STATUS_PENDING) {
            $checkout->forceFill(['status' => SubscriptionCheckout::STATUS_CANCELLED])->save();
        }

        return $checkout;
    }
}
