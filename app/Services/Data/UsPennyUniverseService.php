<?php

namespace App\Services\Data;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Builds the tradable US penny-stock universe from real market data:
 *  - listings from the Nasdaq screener API (NASDAQ / NYSE / NYSE American),
 *  - filtered to the penny price band, excluding warrants/units/rights,
 *  - daily OHLCV history + splits from Yahoo Finance,
 *  - sector/industry for recommendations via Nasdaq company profile.
 *
 * Replaces the synthetic dataset so every recommended ticker is a real,
 * tradable US security.
 */
class UsPennyUniverseService
{
    private const SCREENER_URL = 'https://api.nasdaq.com/api/screener/stocks';

    /** @var list<string> */
    private const EXCHANGES = ['NASDAQ', 'NYSE', 'AMEX'];

    /** Non-common-stock listings that pass a naive price filter. */
    private const EXCLUDE_NAME = '/\b(warrants?|units?|rights?|preferred|when.?issued)\b/i';

    public function __construct(private readonly YahooFinanceClient $yahoo) {}

    /**
     * Wipe all market/model domain tables (fresh rebuild).
     */
    public function wipeDomain(): void
    {
        DB::table('stock_performance_tracking')->delete();
        DB::table('daily_recommendations')->delete();
        DB::table('model_runs')->delete();
        DB::table('corporate_events')->delete();
        DB::table('stock_prices')->delete();
        DB::table('stocks')->delete();
        DB::table('macro_environment')->delete();
    }

    /**
     * Penny candidates from the Nasdaq screener (all supported exchanges).
     *
     * @return array{candidates: list<array{symbol: string, name: string, exchange: string, market_cap: int|null}>, listed: int, rejected: int}
     */
    public function candidates(): array
    {
        $min = (float) config('listikhar.penny_min_price');
        $max = (float) config('listikhar.penny_max_price');

        $listed = 0;
        $rejected = 0;
        $bySymbol = [];

        foreach (self::EXCHANGES as $exchange) {
            foreach ($this->listExchange($exchange) as $row) {
                $listed++;

                $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
                $name = trim((string) ($row['name'] ?? ''));
                if ($symbol === '' || ! preg_match('/^[A-Z]{1,6}$/', $symbol)) {
                    $rejected++;

                    continue;
                }
                if (preg_match(self::EXCLUDE_NAME, $name)) {
                    $rejected++;

                    continue;
                }

                $price = $this->parsePrice($row['lastsale'] ?? '');
                if ($price === null || $price < $min || $price > $max) {
                    $rejected++;

                    continue;
                }

                if (isset($bySymbol[$symbol])) {
                    continue;
                }
                $bySymbol[$symbol] = [
                    'symbol' => $symbol,
                    'name' => $name,
                    'exchange' => $exchange,
                    'market_cap' => $this->parseMarketCap($row['marketCap'] ?? ''),
                ];
            }
        }

        $candidates = array_values($bySymbol);
        usort($candidates, fn ($a, $b) => $a['symbol'] <=> $b['symbol']);

        return ['candidates' => $candidates, 'listed' => $listed, 'rejected' => $rejected];
    }

    /**
     * Fetch real daily history for each candidate and persist it.
     *
     * @param  list<array{symbol: string, name: string, exchange: string, market_cap: int|null}>  $candidates
     * @param  callable|null  $progress  fn(int $done, int $total): void
     * @return array<string, mixed>
     */
    public function sync(array $candidates, string $range = '3y', bool $append = false, ?callable $progress = null): array
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $minPrice = (float) config('listikhar.penny_min_price');
        $maxPrice = (float) config('listikhar.penny_max_price');
        $minVol = (int) config('listikhar.min_avg_volume');
        $minDays = (int) config('listikhar.min_history_days');

        $stats = [
            'attempted' => count($candidates),
            'synced' => 0,
            'already_present' => 0,
            'no_history' => 0,
            'off_price_band' => 0,
            'illiquid' => 0,
            'failed' => 0,
            'price_rows' => 0,
            'splits' => 0,
            'failed_symbols' => [],
        ];

        $done = 0;
        foreach ($candidates as $candidate) {
            $done++;
            if ($progress !== null) {
                $progress($done, count($candidates));
            }

            $existing = Stock::where('ticker', $candidate['symbol'])->first();
            if ($append && $existing !== null
                && DB::table('stock_prices')->where('stock_id', $existing->id)->exists()) {
                $stats['already_present']++;

                continue;
            }

            $chart = $this->yahoo->chart($candidate['symbol'], $range, '1d', true);
            usleep(250_000);

            if ($chart === null || $chart['bars'] === []) {
                $stats['failed']++;
                $stats['failed_symbols'][] = $candidate['symbol'];

                continue;
            }

            $bars = $chart['bars'];
            if (count($bars) < $minDays) {
                $stats['no_history']++;

                continue;
            }

            $lastPrice = (float) $bars[count($bars) - 1]['close'];
            if ($lastPrice < $minPrice || $lastPrice > $maxPrice) {
                $stats['off_price_band']++;

                continue;
            }

            $recent = array_slice($bars, -60);
            $avgVolume = (int) round(array_sum(array_column($recent, 'volume')) / count($recent));
            if ($avgVolume < $minVol) {
                $stats['illiquid']++;

                continue;
            }

            $meta = $chart['meta'];
            $stock = Stock::firstOrNew(['ticker' => $candidate['symbol']]);
            $stock->fill([
                'name' => $candidate['name'] !== ''
                    ? $candidate['name']
                    : ($meta['shortName'] ?? $meta['longName'] ?? $candidate['symbol']),
                'exchange' => $meta['fullExchangeName'] ?? $meta['exchangeName'] ?? $candidate['exchange'],
                'sector' => $stock->sector ?: 'Unclassified',
                'industry' => $stock->industry,
                'market_cap' => $candidate['market_cap'] ?? $stock->market_cap,
                'source' => 'yahoo',
                'is_penny' => true,
                'last_price' => $lastPrice,
                'avg_volume' => $avgVolume,
            ]);
            $stock->save();

            $now = now();
            $rows = [];
            foreach ($bars as $bar) {
                $rows[] = [
                    'stock_id' => $stock->id,
                    'trade_date' => $bar['date'],
                    'open' => round($bar['open'], 4),
                    'high' => round($bar['high'], 4),
                    'low' => round($bar['low'], 4),
                    'close' => round($bar['close'], 4),
                    'volume' => max(0, $bar['volume']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('stock_prices')->insertOrIgnore($chunk);
            }
            $stats['price_rows'] += count($rows);

            $stats['splits'] += $this->recordSplits($stock, $chart['splits'] ?? []);
            $stats['synced']++;
        }

        return $stats;
    }

    /**
     * Fill sector/industry for stocks lacking them (Nasdaq company profile).
     * Called for daily picks only — one request per ticker.
     *
     * @param  list<string>  $tickers
     * @return int number of stocks enriched
     */
    public function enrichSectors(array $tickers): int
    {
        $enriched = 0;

        foreach (array_unique($tickers) as $ticker) {
            $stock = Stock::where('ticker', $ticker)
                ->where(fn ($q) => $q->whereNull('sector')->orWhere('sector', 'Unclassified'))
                ->first();
            if ($stock === null) {
                continue;
            }

            $profile = $this->companyProfile($ticker);
            usleep(250_000);
            if ($profile === null) {
                continue;
            }

            $sector = $profile['Sector']['value'] ?? null;
            $industry = $profile['Industry']['value'] ?? null;
            if ($sector !== null && $sector !== '') {
                $stock->forceFill([
                    'sector' => $sector,
                    'industry' => $industry ?: $stock->industry,
                ])->save();
                $enriched++;
            }
        }

        return $enriched;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listExchange(string $exchange): array
    {
        try {
            $response = $this->yahoo->rawGet(self::SCREENER_URL, [
                'query' => ['tableonly' => 'true', 'limit' => 10000, 'exchange' => $exchange],
                'headers' => [
                    'User-Agent' => (string) config('listikhar.yahoo.user_agent'),
                    'Accept' => 'application/json',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            return [];
        }

        return $payload['data']['table']['rows'] ?? [];
    }

    /** @return array<string, mixed>|null */
    private function companyProfile(string $symbol): ?array
    {
        try {
            $response = $this->yahoo->rawGet('https://api.nasdaq.com/api/company/'.rawurlencode($symbol).'/company-profile', [
                'headers' => [
                    'User-Agent' => (string) config('listikhar.yahoo.user_agent'),
                    'Accept' => 'application/json',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (\Throwable) {
            return null;
        }

        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * @param  list<array{date: string, ratio: string}>  $splits
     */
    private function recordSplits(Stock $stock, array $splits): int
    {
        $recorded = 0;
        foreach ($splits as $split) {
            $exists = DB::table('corporate_events')
                ->where('stock_id', $stock->id)
                ->where('event_date', $split['date'])
                ->where('event_type', 'split')
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('corporate_events')->insert([
                'stock_id' => $stock->id,
                'event_date' => $split['date'],
                'event_type' => 'split',
                'title' => 'Stock split '.$split['ratio'],
                'description' => 'Yahoo Finance split event ('.$split['ratio'].')',
                'meta' => json_encode(['ratio' => $split['ratio'], 'source' => 'yahoo']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $recorded++;
        }

        return $recorded;
    }

    private function parsePrice(string $raw): ?float
    {
        $value = trim(str_replace(['$', ','], '', $raw));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function parseMarketCap(string $raw): ?int
    {
        $value = trim(str_replace(',', '', $raw));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
