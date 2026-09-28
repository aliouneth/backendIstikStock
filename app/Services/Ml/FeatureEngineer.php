<?php

namespace App\Services\Ml;

/**
 * Deterministic feature engineering for penny-stock ranking.
 *
 * Input is a chronological window of price bars (at least
 * config('listikhar.model.lookback_days')), the macro rows aligned to the
 * *same dates*, and the stock's corporate events. Output is a feature => value
 * map matching self::NAMES exactly, or null when history is insufficient.
 */
final class FeatureEngineer
{
    public const MIN_BARS = 65;

    /**
     * Ordered feature names — order is part of the model artifact.
     *
     * @var list<string>
     */
    public const NAMES = [
        // Price behaviour
        'ret_1d',
        'ret_5d',
        'ret_10d',
        'ret_20d',
        'ret_60d',
        'vol_20d',
        'vol_ratio_20_60',
        'volume_ratio_5_20',
        'rel_volume',
        'trend_slope_20',
        'trend_r2_20',
        'dist_high_60',
        'dist_low_60',
        'range_pos_20',
        'log_price',
        'down_days_ratio_10',
        'log_avg_volume_20',
        'log_market_cap',
        'beta_rut_20',
        // Macro environment
        'fed_funds_rate',
        'fed_trend_63',
        'cpi_yoy',
        'cpi_trend_63',
        'vix_level',
        'vix_change_10',
        'sp500_ret_20',
        'nasdaq_ret_20',
        'russell2000_ret_20',
        // Corporate events
        'offering_recent_30d',
        'split_recent_90d',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $prices  chronological bars: date, close, volume
     * @param  array<int, array<string, mixed>>  $macro  macro rows aligned 1:1 with $prices (same dates)
     * @param  array<int, array<string, mixed>>  $events  corporate events (event_date, event_type)
     * @param  array<string, mixed>  $meta  stock meta: market_cap
     * @return array<string, float>|null
     */
    public static function compute(array $prices, array $macro, array $events, array $meta = []): ?array
    {
        $n = count($prices);
        if ($n < self::MIN_BARS || count($macro) !== $n) {
            return null;
        }

        $close = [];
        $volume = [];
        foreach ($prices as $bar) {
            $close[] = (float) $bar['close'];
            $volume[] = (float) $bar['volume'];
        }

        $t = $n - 1;
        if ($close[$t] <= 0) {
            return null;
        }

        $rets = self::returns($close);
        $retsRut = [];
        foreach ($macro as $row) {
            $retsRut[] = (float) ($row['russell2000_close'] ?? 0.0);
        }
        $rutRets = self::returns($retsRut);

        $vol20 = self::std(array_slice($rets, max(0, $t - 19), min(20, $t + 1)));
        $vol60 = self::std(array_slice($rets, max(0, $t - 59), min(60, $t + 1)));

        [$slope20, $r220] = self::logTrend(array_slice($close, $t - 19, 20));

        $high60 = max(array_slice($close, $t - 59, 60));
        $low60 = min(array_slice($close, $t - 59, 60));
        $high20 = max(array_slice($close, $t - 19, 20));
        $low20 = min(array_slice($close, $t - 19, 20));

        $avgVol20 = self::mean(array_slice($volume, $t - 19, 20));
        $avgVol5 = self::mean(array_slice($volume, $t - 4, 5));
        $avgVolPrev20 = self::mean(array_slice($volume, $t - 24, 20));

        $downDays = 0;
        for ($i = $t - 9; $i <= $t; $i++) {
            if ($close[$i] < $close[$i - 1]) {
                $downDays++;
            }
        }

        $macroLast = $macro[$t];
        $macroLag = $macro[max(0, $t - 63)];

        $fed = (float) ($macroLast['fed_funds_rate'] ?? 0.0);
        $cpi = (float) ($macroLast['cpi_yoy'] ?? 0.0);
        $vix = (float) ($macroLast['vix_close'] ?? 0.0);
        $vixLag = (float) ($macro[max(0, $t - 10)]['vix_close'] ?? $vix);

        $offering = self::hasEvent($events, 'offering', 30, self::dateKey($prices[$t]['date'] ?? $prices[$t]['trade_date']));
        $split = self::hasEvent($events, 'split', 90, self::dateKey($prices[$t]['date'] ?? $prices[$t]['trade_date']));

        $marketCap = (float) ($meta['market_cap'] ?? 0.0);

        return [
            'ret_1d' => self::ret($close, $t, 1),
            'ret_5d' => self::ret($close, $t, 5),
            'ret_10d' => self::ret($close, $t, 10),
            'ret_20d' => self::ret($close, $t, 20),
            'ret_60d' => self::ret($close, $t, 60),
            'vol_20d' => $vol20,
            'vol_ratio_20_60' => $vol60 > 1e-9 ? $vol20 / $vol60 : 1.0,
            'volume_ratio_5_20' => $avgVolPrev20 > 0 ? $avgVol5 / $avgVolPrev20 : 1.0,
            'rel_volume' => $avgVol20 > 0 ? $volume[$t] / $avgVol20 : 1.0,
            'trend_slope_20' => $slope20,
            'trend_r2_20' => $r220,
            'dist_high_60' => $high60 > 0 ? ($close[$t] - $high60) / $high60 : 0.0,
            'dist_low_60' => $low60 > 0 ? ($close[$t] - $low60) / $low60 : 0.0,
            'range_pos_20' => $high20 > $low20 ? ($close[$t] - $low20) / ($high20 - $low20) : 0.5,
            'log_price' => log($close[$t]),
            'down_days_ratio_10' => $downDays / 10,
            'log_avg_volume_20' => log(max(1.0, $avgVol20)),
            'log_market_cap' => log(max(1.0, $marketCap)),
            'beta_rut_20' => self::beta(
                array_slice($rets, $t - 19, 20),
                array_slice($rutRets, $t - 19, 20)
            ),
            'fed_funds_rate' => $fed,
            'fed_trend_63' => $fed - (float) ($macroLag['fed_funds_rate'] ?? $fed),
            'cpi_yoy' => $cpi,
            'cpi_trend_63' => $cpi - (float) ($macroLag['cpi_yoy'] ?? $cpi),
            'vix_level' => $vix,
            'vix_change_10' => $vix - $vixLag,
            'sp500_ret_20' => self::macroRet($macro, $t, 'sp500_close', 20),
            'nasdaq_ret_20' => self::macroRet($macro, $t, 'nasdaq_close', 20),
            'russell2000_ret_20' => self::macroRet($macro, $t, 'russell2000_close', 20),
            'offering_recent_30d' => $offering ? 1.0 : 0.0,
            'split_recent_90d' => $split ? 1.0 : 0.0,
        ];
    }

    /**
     * Align price bars to macro rows; bars without macro data are dropped.
     *
     * @param  array<int, array<string, mixed>>  $prices
     * @param  array<string, array<string, mixed>>  $macroByDate
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public static function align(array $prices, array $macroByDate): array
    {
        $p = [];
        $m = [];
        foreach ($prices as $bar) {
            $raw = $bar['date'] ?? $bar['trade_date'] ?? null;
            if ($raw === null) {
                continue;
            }
            $key = self::dateKey($raw);
            if (isset($macroByDate[$key])) {
                $p[] = $bar;
                $m[] = $macroByDate[$key];
            }
        }

        return [$p, $m];
    }

    public static function dateKey(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function returns(array $values): array
    {
        $out = [0.0];
        $n = count($values);
        for ($i = 1; $i < $n; $i++) {
            $prev = $values[$i - 1];
            $out[] = $prev > 0 ? $values[$i] / $prev - 1 : 0.0;
        }

        return $out;
    }

    /** @param  list<float>  $values */
    private static function ret(array $close, int $t, int $k): float
    {
        if ($t - $k < 0 || $close[$t - $k] <= 0) {
            return 0.0;
        }

        return $close[$t] / $close[$t - $k] - 1;
    }

    private static function macroRet(array $macro, int $t, string $col, int $k): float
    {
        $lag = max(0, $t - $k);
        $now = (float) ($macro[$t][$col] ?? 0.0);
        $before = (float) ($macro[$lag][$col] ?? 0.0);

        return $before > 0 ? $now / $before - 1 : 0.0;
    }

    /** @param  list<float>  $values */
    private static function mean(array $values): float
    {
        return count($values) > 0 ? array_sum($values) / count($values) : 0.0;
    }

    /** @param  list<float>  $values */
    private static function std(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $var = 0.0;
        foreach ($values as $v) {
            $var += ($v - $mean) * ($v - $mean);
        }

        return sqrt($var / $n);
    }

    /**
     * OLS slope and R² of log(price) over the last $k points (x = 0..k-1).
     *
     * @param  list<float>  $window
     * @return array{0: float, 1: float}
     */
    private static function logTrend(array $window): array
    {
        $k = count($window);
        if ($k < 3) {
            return [0.0, 0.0];
        }

        $ys = [];
        foreach ($window as $price) {
            $ys[] = $price > 0 ? log($price) : 0.0;
        }

        $meanX = ($k - 1) / 2;
        $meanY = array_sum($ys) / $k;

        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $k; $i++) {
            $dx = $i - $meanX;
            $dy = $ys[$i] - $meanY;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }

        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
        $r2 = ($sxx > 0 && $syy > 0) ? ($sxy * $sxy) / ($sxx * $syy) : 0.0;

        return [$slope * 20.0, $r2]; // slope scaled to ~% per 20 sessions
    }

    /**
     * @param  list<float>  $y  stock returns
     * @param  list<float>  $x  benchmark returns
     */
    private static function beta(array $y, array $x): float
    {
        $n = min(count($x), count($y));
        if ($n < 5) {
            return 1.0;
        }

        $meanX = array_sum(array_slice($x, 0, $n)) / $n;
        $meanY = array_sum(array_slice($y, 0, $n)) / $n;

        $cov = 0.0;
        $varX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $cov += ($x[$i] - $meanX) * ($y[$i] - $meanY);
            $varX += ($x[$i] - $meanX) * ($x[$i] - $meanX);
        }

        return $varX > 1e-12 ? $cov / $varX : 1.0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private static function hasEvent(array $events, string $type, int $days, string $asOf): bool
    {
        if ($events === []) {
            return false;
        }

        $cutoff = (new \DateTimeImmutable($asOf))->modify("-{$days} days");
        foreach ($events as $event) {
            if (($event['event_type'] ?? '') !== $type) {
                continue;
            }
            $when = new \DateTimeImmutable(self::dateKey($event['event_date']));
            if ($when >= $cutoff && $when->format('Y-m-d') <= $asOf) {
                return true;
            }
        }

        return false;
    }
}
