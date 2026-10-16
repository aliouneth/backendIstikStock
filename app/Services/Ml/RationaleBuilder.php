<?php

namespace App\Services\Ml;

/**
 * Turns (features, model contributions) into a short, human-readable
 * explanation of why the model ranked a stock highly.
 */
final class RationaleBuilder
{
    /**
     * @param  array<string, float>  $features
     * @param  array<string, float>  $contributions
     * @param  array<string, mixed>  $context
     */
    public static function build(array $features, array $contributions, array $context = []): string
    {
        $positive = [];
        foreach ($contributions as $name => $value) {
            if ($value > 1e-7) {
                $positive[$name] = $value;
            }
        }
        if ($positive === []) {
            foreach ($contributions as $name => $value) {
                $positive[$name] = abs($value);
            }
        }
        arsort($positive);

        $phrases = [];
        $macroUsed = false;
        $stockUsed = 0;

        foreach (array_keys($positive) as $name) {
            $phrase = self::phrase($name, $features[$name] ?? 0.0, $context);
            if ($phrase === null) {
                continue;
            }
            if (self::isMacro($name)) {
                if ($macroUsed) {
                    continue;
                }
                $macroUsed = true;
            } else {
                if ($stockUsed >= 2) {
                    continue;
                }
                $stockUsed++;
            }
            $phrases[] = $phrase;

            if (count($phrases) >= 3) {
                break;
            }
        }

        if ($phrases === []) {
            $phrases[] = 'Model score driven by combined price structure and macro alignment';
        }

        $sector = $context['sector'] ?? null;
        $summary = implode('; ', $phrases);

        return $sector !== null
            ? ucfirst($summary).' — favorable setup in '.$sector.'.'
            : ucfirst($summary).'.';
    }

    private static function isMacro(string $name): bool
    {
        return in_array($name, [
            'fed_funds_rate', 'fed_trend_63', 'cpi_yoy', 'cpi_trend_63',
            'vix_level', 'vix_change_10', 'sp500_ret_20', 'nasdaq_ret_20',
            'russell2000_ret_20',
        ], true);
    }

    /** @param  array<string, mixed>  $context */
    private static function phrase(string $name, float $v, array $context): ?string
    {
        $pct = fn (float $x): string => ($x >= 0 ? '+' : '').number_format($x * 100, 1).'%';

        return match ($name) {
            'ret_20d' => abs($v) < 0.03
                ? null
                : ($v > 0
                    ? '20-day momentum '.$pct($v)
                    : 'deep pullback ('.$pct($v).' over 20d) — mean-reversion setup'),
            'ret_5d' => abs($v) < 0.05 ? null : '5-day momentum '.$pct($v),
            'ret_1d' => abs($v) < 0.06 ? null : 'sharp 1-day move '.$pct($v),
            'ret_60d' => $v > 0.3 ? 'strong 60-day uptrend '.$pct($v) : null,
            'rel_volume' => $v >= 1.4
                ? 'volume at '.number_format($v, 1).'x its 20-day average'
                : null,
            'volume_ratio_5_20' => $v >= 1.3
                ? '5-day volume '.number_format($v, 1).'x the prior baseline'
                : null,
            'trend_slope_20' => $v > 0.02
                ? 'clean 20-day uptrend in force'
                : ($v < -0.03 ? 'downtrend decelerating' : null),
            'range_pos_20' => $v >= 0.85 ? 'trading near 20-day highs' : null,
            'gap_pct' => abs($v) >= 0.05 ? 'overnight gap '.$pct($v).' from the prior close' : null,
            'dist_low_60' => $v > 0.6 ? 'bounced '.number_format($v * 100, 0).'% off the 60-day low' : null,
            'down_days_ratio_10' => $v <= 0.2 ? 'persistent bid — 80%+ up days in the last 10' : null,
            'log_avg_volume_20' => null,
            'log_market_cap' => null,
            'log_price' => null,
            'vol_20d' => $v > 0.09 ? 'high realized volatility ('.number_format($v * 100, 1).'% daily)' : null,
            'beta_rut_20' => $v > 1.4 ? 'high small-cap beta ('.number_format($v, 2).'x Russell 2000)' : null,
            'fed_trend_63' => $v <= -0.05
                ? 'Fed cutting — '.number_format($v, 2).'pts over 3 months'
                : ($v >= 0.05 ? 'Fed hiking — +'.number_format($v, 2).'pts over 3 months' : null),
            'fed_funds_rate' => $v >= 4.5 ? 'rates restrictive at '.number_format($v, 2).'%' : ($v <= 2.5 ? 'easy money at '.number_format($v, 2).'%' : null),
            'cpi_trend_63' => $v <= -0.1 ? 'inflation easing ('.number_format($v, 2).'pts in 3 months)' : ($v >= 0.1 ? 'inflation re-accelerating (+'.number_format($v, 2).'pts)' : null),
            'vix_change_10' => $v <= -1.0 ? 'volatility cooling — VIX '.number_format($v, 1).' over 10 days' : ($v >= 3 ? 'volatility spiking — VIX +'.number_format($v, 1) : null),
            'vix_level' => $v >= 25 ? 'risk-off regime (VIX '.number_format($v, 1).')' : ($v <= 12.5 ? 'complacent tape (VIX '.number_format($v, 1).')' : null),
            'russell2000_ret_20' => abs($v) < 0.02 ? null : 'Russell 2000 '.$pct($v).' over 20 days',
            'sp500_ret_20' => $v > 0.04 ? 'S&P 500 tailwind '.$pct($v) : null,
            'nasdaq_ret_20' => $v > 0.05 ? 'Nasdaq leadership '.$pct($v) : null,
            'offering_recent_30d' => $v >= 1 ? 'recent financing event absorbed by the tape' : null,
            'split_recent_90d' => $v >= 1 ? 'recent split — retail attention window' : null,
            default => null,
        };
    }
}
