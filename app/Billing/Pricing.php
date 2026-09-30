<?php

namespace App\Billing;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

class Pricing
{
    public const MONTHLY = 'monthly';

    public const ANNUAL = 'annual';

    /** Annual plans default to 10x the monthly price (2 months free). */
    public const ANNUAL_MONTHS_FACTOR = 10;

    public const PERIODS = [self::MONTHLY, self::ANNUAL];

    public static function isValidPeriod(?string $period): bool
    {
        return in_array($period, self::PERIODS, true);
    }

    /**
     * The amount actually charged for one period of the plan.
     */
    public static function amountFor(Subscription $plan, string $period): float
    {
        if ($period === self::ANNUAL) {
            if ($plan->annual_price !== null) {
                return round((float) $plan->annual_price, 2);
            }

            return round((float) $plan->price * self::ANNUAL_MONTHS_FACTOR, 2);
        }

        return round((float) $plan->price, 2);
    }

    /**
     * Price when paying monthly for a full year - used only to show the saving.
     */
    public static function annualListPrice(Subscription $plan): float
    {
        return round((float) $plan->price * 12, 2);
    }

    public static function savingsFor(Subscription $plan, string $period): float
    {
        if ($period !== self::ANNUAL) {
            return 0.0;
        }

        $saving = self::annualListPrice($plan) - self::amountFor($plan, self::ANNUAL);

        return $saving > 0 ? round($saving, 2) : 0.0;
    }

    public static function periodEndsAt(string $period, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();

        return $period === self::ANNUAL
            ? $from->addYear()
            : $from->addMonth();
    }
}
