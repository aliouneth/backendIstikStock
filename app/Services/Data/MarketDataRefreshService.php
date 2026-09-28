<?php

namespace App\Services\Data;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the latest daily OHLCV history for the stored tradable universe
 * from Yahoo Finance and upserts it. By default the whole universe is
 * refreshed, but only when new market data is actually available (the
 * latest Russell/S&P session is ahead of our stored bars). Failure at any
 * point is non-fatal: the pipeline falls back to the stored dataset.
 */
class MarketDataRefreshService
{
    public function __construct(private readonly YahooFinanceClient $yahoo) {}

    /**
     * @param  list<string>|null  $tickers  explicit symbols, or null = whole stored universe
     * @param  bool  $onlyIfStale  skip when stored bars are already current
     * @return array<string, mixed>
     */
    public function refresh(?array $tickers = null, bool $onlyIfStale = true): array
    {
        $wholeUniverse = $tickers === null;
        if ($wholeUniverse) {
            $tickers = Stock::orderBy('ticker')->pluck('ticker')->all();
        }

        if ($tickers === []) {
            return ['attempted' => 0, 'updated' => 0, 'failed' => [], 'note' => 'no stocks stored'];
        }

        if ($wholeUniverse && $onlyIfStale && ! $this->isStale()) {
            return ['attempted' => 0, 'updated' => 0, 'failed' => [], 'note' => 'stored bars already current'];
        }

        $updated = 0;
        $failed = [];

        foreach ($tickers as $symbol) {
            try {
                if ($this->refreshSymbol($symbol)) {
                    $updated++;
                } else {
                    $failed[] = $symbol;
                }
            } catch (\Throwable $e) {
                $failed[] = $symbol;
                Log::warning('Live refresh failed for symbol', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ]);
            }
            usleep(150_000);
        }

        return [
            'attempted' => count($tickers),
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * True when the latest index session is newer than our stored bars.
     */
    private function isStale(): bool
    {
        $maxStock = DB::table('stock_prices')->max('trade_date');
        if ($maxStock === null) {
            return true;
        }

        $market = $this->yahoo->chart('^GSPC', '5d', '1d');
        if ($market === null || $market['bars'] === []) {
            return false; // cannot determine freshness — do not hammer Yahoo
        }

        $latestMarket = $market['bars'][count($market['bars']) - 1]['date'];

        return $latestMarket > (string) $maxStock;
    }

    private function refreshSymbol(string $symbol): bool
    {
        $stock = Stock::firstWhere('ticker', $symbol);
        $hasHistory = $stock !== null && DB::table('stock_prices')
            ->where('stock_id', $stock->id)
            ->exists();

        // Incremental update when history exists; full fetch for new symbols.
        $chart = $this->yahoo->chart($symbol, $hasHistory ? '1mo' : '2y', '1d');
        if ($chart === null || count($chart['bars']) < ($hasHistory ? 1 : 70)) {
            return false;
        }

        $meta = $chart['meta'];

        $stock ??= new Stock(['ticker' => $symbol]);
        $stock->fill([
            'name' => $stock->name ?? ($meta['shortName'] ?? $meta['longName'] ?? $symbol),
            'exchange' => $meta['fullExchangeName'] ?? $meta['exchangeName'] ?? $stock->exchange,
            'sector' => $stock->sector ?? 'Unclassified',
            'industry' => $stock->industry,
            'source' => 'yahoo',
            'is_penny' => true,
        ]);
        $stock->save();

        $now = now();
        $rows = [];
        foreach ($chart['bars'] as $bar) {
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

        $recent = array_slice($chart['bars'], -60);
        $avgVolume = (int) round(array_sum(array_column($recent, 'volume')) / count($recent));
        $lastPrice = (float) $chart['bars'][count($chart['bars']) - 1]['close'];

        $stock->forceFill([
            'last_price' => $lastPrice,
            'avg_volume' => $avgVolume,
        ])->save();

        return true;
    }
}
