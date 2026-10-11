<?php

namespace App\Models;

use App\Billing\Pricing;
use App\Support\Features;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'description',
    'price',
    'annual_price',
    'stripe_product_id',
    'billing_period',
    'features',
    'is_active',
    'is_default',
    'sort_order',
])]
class Subscription extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return list<string>
     */
    public function featureKeys(): array
    {
        return Features::sanitize($this->features);
    }

    public function grants(string $feature): bool
    {
        return in_array($feature, $this->featureKeys(), true);
    }

    /**
     * Charge for one period. Falls back to 10x the monthly price when the
     * administrator has not set an explicit annual price.
     */
    public function priceFor(string $period): float
    {
        return Pricing::amountFor($this, $period);
    }

    public function supportsPeriod(string $period): bool
    {
        return Pricing::isValidPeriod($period);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float) $this->price,
            'annual_price' => $this->annual_price === null ? null : (float) $this->annual_price,
            'annual_price_effective' => Pricing::amountFor($this, Pricing::ANNUAL),
            'annual_savings' => Pricing::savingsFor($this, Pricing::ANNUAL),
            'currency' => config('billing.currency', 'usd'),
            'billing_period' => $this->billing_period,
            'features' => $this->featureKeys(),
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'sort_order' => (int) $this->sort_order,
            'users_count' => $this->users_count ?? $this->users()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
