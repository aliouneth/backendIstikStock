<?php

namespace App\Http\Controllers\Api;

use App\Billing\Stripe\StripeSubscriptionSyncer;
use App\Http\Controllers\Controller;
use App\Models\StripeWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Throwable;

/**
 * Receives Stripe webhooks.
 *
 * This route is deliberately unauthenticated (Stripe cannot send our API token)
 * and is authenticated by the signed payload instead. Every event is stored
 * first so retries are idempotent.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeSubscriptionSyncer $syncer) {}

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            return response('Stripe webhook secret is not configured.', 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (UnexpectedValueException $e) {
            return response('Invalid payload.', 400);
        } catch (SignatureVerificationException $e) {
            return response('Invalid signature.', 400);
        }

        $object = $event->data->object->toArray();

        $record = StripeWebhookEvent::query()->firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['type' => $event->type, 'payload' => $object],
        );

        if ($record->isProcessed()) {
            return response('Already processed.', 200);
        }

        try {
            $this->syncer->handle($event->type, $object);
            $record->forceFill(['processed_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);

            return response('Webhook handling failed.', 500);
        }

        return response('OK.', 200);
    }
}
