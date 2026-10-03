<?php

namespace App\Models;

use App\Support\Features;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'subscription_id',
    'subscription_expires_at',
    'auto_renew',
    'is_active',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token', 'api_token_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'auto_renew' => 'boolean',
            'last_login_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<SubscriptionCheckout, $this>
     */
    public function checkouts(): HasMany
    {
        return $this->hasMany(SubscriptionCheckout::class);
    }

    /**
     * @return HasMany<Watchlist, $this>
     */
    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function hasExpiredSubscription(): bool
    {
        return $this->subscription_expires_at !== null && $this->subscription_expires_at->isPast();
    }

    public function canAccessFeature(string $feature): bool
    {
        if (! Features::isValid($feature)) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $subscription = $this->subscription;

        if (! $subscription instanceof Subscription || ! $subscription->is_active) {
            return false;
        }

        if ($this->hasExpiredSubscription()) {
            return false;
        }

        return $subscription->grants($feature);
    }

    /**
     * @return list<string>
     */
    public function featureKeys(): array
    {
        if ($this->isAdmin()) {
            return Features::keys();
        }

        if (! $this->isActive() || $this->hasExpiredSubscription()) {
            return [];
        }

        return $this->subscription?->featureKeys() ?? [];
    }

    /**
     * Issue a fresh plain-text API token and persist only its SHA-256 hash.
     *
     * @return array{plain: string, hash: string}
     */
    public static function issueToken(): array
    {
        $plain = Str::random(64);

        return ['plain' => $plain, 'hash' => hash('sha256', $plain)];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->isActive(),
            'subscription_id' => $this->subscription_id,
            'subscription' => $this->subscription?->toApiArray(),
            'subscription_expires_at' => $this->subscription_expires_at?->toIso8601String(),
            'auto_renew' => (bool) $this->auto_renew,
            'features' => $this->featureKeys(),
            'is_admin' => $this->isAdmin(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
