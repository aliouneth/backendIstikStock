<?php

namespace App\Http\Controllers\Api;

use App\Billing\CheckoutService;
use App\Billing\Pricing;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkouts) {}

    /**
     * Quote for a plan without creating anything - powers the pay step.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $this->validateQuote($request);

        /** @var Subscription $plan */
        $plan = Subscription::query()->active()->findOrFail($data['subscription_id']);

        return response()->json([
            'plan' => $plan->toApiArray(),
            'quote' => $this->quoteFor($plan, $data['billing_period'], $data['auto_renew']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateQuote($request);

        /** @var Subscription $plan */
        $plan = Subscription::query()->active()->findOrFail($data['subscription_id']);

        $user = $request->user();

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'subscription_id' => 'Administrators already have access to every plan.',
            ]);
        }

        $checkout = $this->checkouts->open(
            $user,
            $plan,
            $data['billing_period'],
            $data['auto_renew'],
        );

        return response()->json([
            'checkout' => $checkout->toApiArray(),
            'payment' => $this->checkouts->paymentIntent($checkout),
        ], 201);
    }

    public function show(Request $request, SubscriptionCheckout $checkout): JsonResponse
    {
        $this->authorize($request, $checkout);

        return response()->json([
            'checkout' => $checkout->load('subscription')->toApiArray(),
            'payment' => $checkout->isPending() ? $this->checkouts->paymentIntent($checkout) : null,
        ]);
    }

    /**
     * Settle the checkout. The plan is granted by the service only when the
     * configured driver reports that money was actually captured.
     */
    public function confirm(Request $request, SubscriptionCheckout $checkout): JsonResponse
    {
        $this->authorize($request, $checkout);

        $checkout = $this->checkouts->settle($checkout);

        if (! $checkout->isPaid()) {
            return response()->json([
                'message' => $checkout->failure_reason ?? 'Payment was not completed.',
                'checkout' => $checkout->toApiArray(),
            ], 402);
        }

        return response()->json([
            'message' => 'Subscription updated.',
            'checkout' => $checkout->toApiArray(),
            'user' => $checkout->user->fresh()->load('subscription')->toApiArray(),
        ]);
    }

    public function cancel(Request $request, SubscriptionCheckout $checkout): JsonResponse
    {
        $this->authorize($request, $checkout);

        return response()->json([
            'checkout' => $this->checkouts->cancel($checkout)->toApiArray(),
        ]);
    }

    /**
     * Billing state of the signed-in user, for the account/checkout pages.
     */
    public function subscription(Request $request): JsonResponse
    {
        $user = $request->user()->load('subscription');

        return response()->json([
            'subscription' => $user->subscription?->toApiArray(),
            'auto_renew' => (bool) $user->auto_renew,
            'expires_at' => $user->subscription_expires_at?->toIso8601String(),
            'is_expired' => $user->hasExpiredSubscription(),
            'payment_collected' => $this->checkouts->driverCollectsPayment(),
            'recent_checkouts' => $user->checkouts()
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (SubscriptionCheckout $c) => $c->toApiArray()),
        ]);
    }

    /**
     * @return array{subscription_id: int, billing_period: string, auto_renew: bool}
     */
    private function validateQuote(Request $request): array
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'integer', 'exists:subscriptions,id'],
            'billing_period' => ['required', 'string', 'in:'.implode(',', Pricing::PERIODS)],
            // Coerced with boolean() below rather than the "boolean" rule,
            // which only accepts 0/1 and rejects the "true" a query string sends.
            'auto_renew' => ['sometimes'],
        ]);

        return [
            'subscription_id' => (int) $data['subscription_id'],
            'billing_period' => $data['billing_period'],
            'auto_renew' => $request->boolean('auto_renew'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteFor(Subscription $plan, string $period, bool $autoRenew): array
    {
        $amount = Pricing::amountFor($plan, $period);
        $endsAt = Pricing::periodEndsAt($period);

        return [
            'billing_period' => $period,
            'is_annual' => $period === Pricing::ANNUAL,
            'auto_renew' => $autoRenew,
            'amount' => $amount,
            'currency' => config('billing.currency', 'usd'),
            'savings' => Pricing::savingsFor($plan, $period),
            'renews_at' => $autoRenew ? null : $endsAt->toIso8601String(),
            'line_items' => [
                [
                    'label' => $plan->name.' - '.($period === Pricing::ANNUAL ? 'annual' : 'monthly'),
                    'amount' => $amount,
                ],
            ],
        ];
    }

    private function authorize(Request $request, SubscriptionCheckout $checkout): void
    {
        $user = $request->user();

        if ($user->isAdmin() || $checkout->user_id === $user->id) {
            return;
        }

        throw new AccessDeniedHttpException('That checkout belongs to another account.');
    }
}
