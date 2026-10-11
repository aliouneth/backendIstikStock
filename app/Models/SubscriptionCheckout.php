<?php

namespace App\Models;

use App\Billing\Pricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionCheckout extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'subscription_id',
        'plan_name',
        'billing_period',
        'amount',
        'currency',
        'auto_renew',
        'status',
        'driver',
        'provider_reference',
        'stripe_subscription_id',
        'paid_at',
        'period_ends_at',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'auto_renew' => 'boolean',
        'paid_at' => 'datetime',
        'period_ends_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->subscription_id,
            'plan_name' => $this->plan_name,
            'billing_period' => $this->billing_period,
            'is_annual' => $this->billing_period === Pricing::ANNUAL,
            'auto_renew' => (bool) $this->auto_renew,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'driver' => $this->driver,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'period_ends_at' => $this->period_ends_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
