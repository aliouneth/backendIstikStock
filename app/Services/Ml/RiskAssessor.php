<?php

namespace App\Services\Ml;

/**
 * Deterministic, post-model risk overlay for ranked candidates.
 *
 * These flags intentionally do not alter the trained model's score. They make
 * downside, liquidity, and financing risks visible alongside upside-oriented
 * predictions.
 */
final class RiskAssessor
{
    public const SUB_DOLLAR_PRICE = 1.0;

    public const MIN_AVG_DOLLAR_VOLUME = 500_000.0;

    public const HIGH_VOLATILITY = 0.15;

    public const HIGH_BETA = 2.0;

    public const LARGE_GAP = 0.10;

    public const PERSISTENT_SELLING = 0.8;

    public const NANO_CAP = 50_000_000.0;

    /**
     * @param  array<string, float>  $features
     * @return list<array{code: string, label: string, detail: string}>
     */
    public static function assess(array $features, float $price, float $avgVolume, ?float $marketCap = null): array
    {
        $risks = [];

        if ($price > 0 && $price < self::SUB_DOLLAR_PRICE) {
            $risks[] = [
                'code' => 'sub_dollar_price',
                'label' => 'Sub-$1.00 price',
                'detail' => 'Wider spreads and heightened compliance/delist risk below $1.00.',
            ];
        }

        if ($avgVolume > 0 && $price > 0) {
            $dollarVolume = $price * $avgVolume;
            if ($dollarVolume < self::MIN_AVG_DOLLAR_VOLUME) {
                $risks[] = [
                    'code' => 'thin_dollar_liquidity',
                    'label' => 'Thin dollar liquidity',
                    'detail' => 'About '.self::money($dollarVolume).' of average daily value over 20 days; large orders may move the price.',
                ];
            }
        }

        $volatility = $features['vol_20d'] ?? 0.0;
        if ($volatility >= self::HIGH_VOLATILITY) {
            $risks[] = [
                'code' => 'high_volatility',
                'label' => 'High trailing volatility',
                'detail' => 'A 20-day daily standard deviation near '.round($volatility * 100, 1).'%; sharp adverse moves are common.',
            ];
        }

        $beta = $features['beta_rut_20'] ?? 1.0;
        if ($beta >= self::HIGH_BETA) {
            $risks[] = [
                'code' => 'high_small_cap_beta',
                'label' => 'High small-cap beta',
                'detail' => 'Moves about '.number_format($beta, 2).'x with the Russell 2000; broad small-cap weakness is amplified.',
            ];
        }

        if (($features['offering_recent_30d'] ?? 0.0) >= 1.0) {
            $risks[] = [
                'code' => 'recent_offering',
                'label' => 'Recent offering',
                'detail' => 'A financing event occurred within the last 30 days; dilution or share overhang may weigh on follow-through.',
            ];
        }

        $gap = $features['gap_pct'] ?? 0.0;
        if ($gap >= self::LARGE_GAP) {
            $risks[] = [
                'code' => 'extended_overnight_gap',
                'label' => 'Extended overnight gap',
                'detail' => 'Opened '.round($gap * 100, 1).'% above the prior close; chasing the open has elevated reversal risk.',
            ];
        } elseif ($gap <= -self::LARGE_GAP) {
            $risks[] = [
                'code' => 'sharp_overnight_decline',
                'label' => 'Sharp overnight decline',
                'detail' => 'Opened '.round(abs($gap) * 100, 1).'% below the prior close; weak-tape or halt/reopen risk.',
            ];
        }

        $downDays = $features['down_days_ratio_10'] ?? 0.0;
        if ($downDays >= self::PERSISTENT_SELLING) {
            $risks[] = [
                'code' => 'persistent_selling',
                'label' => 'Persistent selling',
                'detail' => 'Down in '.(int) round($downDays * 10).' of the last 10 sessions; buying remains against the tape.',
            ];
        }

        if ($marketCap !== null && $marketCap > 0 && $marketCap < self::NANO_CAP) {
            $risks[] = [
                'code' => 'nano_cap',
                'label' => 'Nano-cap issuer',
                'detail' => 'Market capitalization near '.self::money($marketCap).'; financing fragility and promotion risk are elevated.',
            ];
        }

        return $risks;
    }

    private static function money(float $value): string
    {
        if ($value >= 1_000_000_000) {
            return '$'.round($value / 1_000_000_000, 2).'B';
        }
        if ($value >= 1_000_000) {
            return '$'.round($value / 1_000_000, 2).'M';
        }
        if ($value >= 1_000) {
            return '$'.round($value / 1_000, 1).'K';
        }

        return '$'.round($value, 0);
    }
}
