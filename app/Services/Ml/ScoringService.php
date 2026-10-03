<?php

namespace App\Services\Ml;

use App\Models\Stock;
use App\Services\Data\UsPennyUniverseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Inference: builds the tradable penny-stock universe, computes features with
 * the latest macro environment, scores every candidate with the trained model
 * and returns the ranked top picks with per-pick explanations.
 */
class ScoringService
{
    public function __construct(
        private readonly ModelRepository $repo,
        private readonly UsPennyUniverseService $universe,
    ) {}

    /**
     * @param  string|null  $asOf  trading date to score for (default: latest available)
     * @return array{
     *   date: string,
     *   universe: int,
     *   scored: int,
     *   skipped: int,
     *   model_version: string,
     *   items: list<array<string, mixed>>
     * }
     */
    public function topPicks(int $topN, ?string $asOf = null): array
    {
        $date = $this->resolveDate($asOf);
        $universe = $this->scoreUniverse($date);
        $model = $this->repo->loadOrFail();

        $scored = $universe['scored'];
        $top = array_slice($scored, 0, $topN);

        // Resolve real sector/industry for picks not enriched yet (one
        // keyless Nasdaq request per ticker, cached on the stocks row).
        $toEnrich = [];
        foreach ($top as $row) {
            $sector = $row['stock']['sector'];
            if ($sector === null || $sector === '' || $sector === 'Unclassified') {
                $toEnrich[] = $row['stock']['ticker'];
            }
        }
        if ($toEnrich !== []) {
            $this->universe->enrichSectors($toEnrich);
            $freshSectors = Stock::whereIn('ticker', $toEnrich)->pluck('sector', 'ticker');
            foreach ($top as &$row) {
                $fresh = $freshSectors[$row['stock']['ticker']] ?? null;
                if ($fresh !== null && $fresh !== '') {
                    $row['stock']['sector'] = $fresh;
                }
            }
            unset($row);
        }

        $items = [];
        foreach ($top as $rank => $row) {
            $items[] = $this->toItem($model, $row, $rank + 1);
        }

        return [
            'date' => $date,
            'universe' => $universe['universe'],
            'scored' => $universe['scored_count'],
            'skipped' => $universe['skipped'],
            'model_version' => $model->version(),
            'items' => $items,
        ];
    }

    /**
     * Score one symbol against the model, using the same universe-relative
     * normalisation as topPicks() so a single stock's 0-100 score and rank are
     * directly comparable to the "Today's picks" list.
     *
     * @return array<string, mixed>
     */
    public function evaluateSymbol(string $ticker, ?string $asOf = null): array
    {
        $result = $this->evaluateSymbols([$ticker], $asOf);

        return $result['evaluations'][strtoupper($ticker)] ?? throw new \RuntimeException("Unknown ticker [{$ticker}].");
    }

    /**
     * Bulk-evaluate a set of symbols against a single scored universe.
     *
     * @param  list<string>  $tickers
     * @return array{date: string, model_version: string, universe: int, scored: int, skipped: int, evaluations: array<string, array<string, mixed>>}
     */
    public function evaluateSymbols(array $tickers, ?string $asOf = null): array
    {
        $date = $this->resolveDate($asOf);
        $universe = $this->scoreUniverse($date);
        $model = $this->repo->loadOrFail();

        $map = [];
        foreach (array_values($tickers) as $t) {
            $map[strtoupper($t)] = null;
        }

        $stockIds = [];
        $stocksById = [];
        foreach (Stock::query()->whereIn('ticker', array_keys($map))->get() as $stock) {
            $stocksById[(int) $stock->id] = $stock;
            $map[strtoupper($stock->ticker)] = $stock;
            $stockIds[] = (int) $stock->id;
        }

        $baseCommon = [
            'date' => $date,
            'model_version' => $model->version(),
            'universe' => $universe['universe'],
            'scored' => $universe['scored_count'],
            'skipped' => $universe['skipped'],
        ];

        $evaluations = [];
        $count = $universe['scored_count'];

        foreach ($map as $tickerUpper => $stock) {
            if (! $stock instanceof Stock) {
                throw new \RuntimeException("Unknown ticker [{$tickerUpper}].");
            }

            $rank = null;
            $row = null;
            foreach ($universe['scored'] as $index => $candidate) {
                $cid = (int) $candidate['stock']['id'];
                if ($cid === (int) $stock->id) {
                    $rank = $index + 1;
                    $row = $candidate;

                    break;
                }
            }

            $base = $baseCommon + [
                'ticker' => $stock->ticker,
                'name' => $stock->name,
                'sector' => $stock->sector ?: 'Unclassified',
                'exchange' => $stock->exchange,
                'market_cap' => $stock->market_cap,
            ];

            if ($row === null) {
                $evaluations[$tickerUpper] = $base + [
                    'eligible' => false,
                    'reason' => $this->ineligibleReason($stock, $date),
                ];

                continue;
            }

            $evaluations[$tickerUpper] = $base + [
                'eligible' => true,
                'rank' => $rank,
                'percentile' => round(100 * (1 - (($rank - 1) / max(1, $count))), 2),
                'peers' => $count,
                'item' => $this->toItem($model, $row, $rank),
            ];
        }

        return $baseCommon + ['evaluations' => $evaluations];
    }

    /**
     * @return array<string, mixed>
     */
    private function toItem(RegressorInterface $model, array $row, int $rank): array
    {
        $contributions = $model->explainRow($row['features']);
        $sector = $row['stock']['sector'] ?: 'Unclassified';

        return [
            'rank' => $rank,
            'stock_id' => $row['stock']['id'],
            'ticker' => $row['stock']['ticker'],
            'name' => $row['stock']['name'],
            'sector' => $sector,
            'price' => round($row['price'], 4),
            'avg_volume' => (int) $row['avg_volume'],
            'score' => round($row['score'], 2),
            'raw_score' => round($row['raw'], 6),
            'rationale' => RationaleBuilder::build($row['features'], $contributions, [
                'sector' => $sector,
                'avg_volume' => $row['avg_volume'],
            ]),
            'key_features' => $this->keyFeatures($row['features'], $contributions),
            'features' => $row['features'],
        ];
    }

    private function ineligibleReason(object $stock, string $date): string
    {
        $bars = DB::table('stock_prices')
            ->where('stock_id', $stock->id)
            ->orderByDesc('trade_date')
            ->limit(1)
            ->get(['trade_date', 'close', 'volume']);

        if ($bars->isEmpty()) {
            return 'No price history in the database.';
        }

        $last = $bars->first();

        if ($last->trade_date !== $date) {
            return "Stale data: last bar is {$last->trade_date} but the universe is scored as of {$date}.";
        }

        $lookback = (int) config('listikhar.model.lookback_days');
        $cutoff = date('Y-m-d', strtotime($date.' -'.($lookback + 5).' days'));
        $days = DB::table('stock_prices')
            ->where('stock_id', $stock->id)
            ->where('trade_date', '>=', $cutoff)
            ->where('trade_date', '<=', $date)
            ->count();

        if ($days < (int) config('listikhar.min_history_days')) {
            return "Only {$days} trading days of history; the model needs at least ".config('listikhar.min_history_days').'.';
        }

        $price = (float) $last->close;
        $min = (float) config('listikhar.penny_min_price');
        $max = (float) config('listikhar.penny_max_price');

        if ($price < $min || $price > $max) {
            return sprintf('Price $%.4f is outside the $%.2f-$%.2f penny-stock band.', $price, $min, $max);
        }

        return 'Average volume is below the liquidity floor of '.config('listikhar.min_avg_volume').' shares.';
    }

    private function resolveDate(?string $asOf): string
    {
        $date = $asOf ?? FeatureEngineer::dateKey(
            DB::table('stock_prices')->max('trade_date')
        );

        if (! $date) {
            throw new \RuntimeException('No price data available.');
        }

        return $date;
    }

    /**
     * Score every candidate once and keep the result warm, so a single-stock
     * lookup does not have to re-score the whole universe on every request.
     *
     * @return array{scored: list<array<string, mixed>>, universe: int, scored_count: int, skipped: int}
     */
    private function scoreUniverse(string $date): array
    {
        $cacheKey = "score:{$date}:".$this->repo->loadOrFail()->version();

        return Cache::remember($cacheKey, (int) config('listikhar.score_cache_seconds', 900), function () use ($date) {
            return $this->buildUniverse($date);
        });
    }

    /**
     * @return array{scored: list<array<string, mixed>>, universe: int, scored_count: int, skipped: int}
     */
    private function buildUniverse(string $date): array
    {
        $model = $this->repo->loadOrFail();

        $lookback = (int) config('listikhar.model.lookback_days');
        $cutoff = date('Y-m-d', strtotime($date.' -'.($lookback + 5).' days'));

        $scored = [];
        $skipped = 0;
        $universe = 0;

        foreach ($this->candidateStocks($date) as $stock) {
            $universe++;

            $bars = DB::table('stock_prices')
                ->where('stock_id', $stock->id)
                ->where('trade_date', '>=', $cutoff)
                ->where('trade_date', '<=', $date)
                ->orderBy('trade_date')
                ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
                ->map(fn ($row) => (array) $row)
                ->all();

            $features = $this->featuresFor($stock, $bars, $date);
            if ($features === null) {
                $skipped++;

                continue;
            }

            // Fresh liquidity/price gate using the latest bar.
            $price = $features['_price'];
            $avgVol = $features['_avg_volume'];
            unset($features['_price'], $features['_avg_volume']);

            if ($price < (float) config('listikhar.penny_min_price')
                || $price > (float) config('listikhar.penny_max_price')
                || $avgVol < (int) config('listikhar.min_avg_volume')) {
                $skipped++;

                continue;
            }

            $scored[] = [
                'stock' => (array) $stock,
                'price' => $price,
                'avg_volume' => $avgVol,
                'features' => $features,
                'raw' => $model->predictRow($features),
            ];
        }

        if ($scored === []) {
            throw new \RuntimeException('No candidates survived the penny-stock filters.');
        }

        // Min-max normalize raw forward-return predictions to a 0-100 score.
        $min = min(array_column($scored, 'raw'));
        $max = max(array_column($scored, 'raw'));
        $range = $max - $min;

        foreach ($scored as &$row) {
            $row['score'] = $range > 1e-12 ? 100 * ($row['raw'] - $min) / $range : 50.0;
        }
        unset($row);

        usort($scored, fn ($a, $b) => $b['raw'] <=> $a['raw']);

        return [
            'scored' => $scored,
            'universe' => $universe,
            'scored_count' => count($scored),
            'skipped' => $skipped,
        ];
    }

    /**
     * Candidate universe: penny price band, liquidity floor, enough history,
     * data as of the latest trading day.
     *
     * @return list<\stdClass>
     */
    private function candidateStocks(string $latest): array
    {
        $minDays = (int) config('listikhar.min_history_days');

        $agg = DB::table('stock_prices')
            ->select('stock_id', DB::raw('COUNT(*) as days'), DB::raw('MAX(trade_date) as last_date'))
            ->where('trade_date', '>=', date('Y-m-d', strtotime($latest.' -1 year')))
            ->where('trade_date', '<=', $latest)
            ->groupBy('stock_id');

        return DB::table('stocks as s')
            ->joinSub($agg, 'agg', fn ($join) => $join->on('s.id', '=', 'agg.stock_id'))
            ->where('agg.days', '>=', $minDays)
            ->where('agg.last_date', $latest)
            ->get(['s.id', 's.ticker', 's.name', 's.sector', 's.market_cap'])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $bars
     * @return array<string, float>|null
     */
    private function featuresFor(object $stock, array $bars, string $date): ?array
    {
        $macroByDate = $this->macroIndex($date);
        [$prices, $macro] = FeatureEngineer::align($bars, $macroByDate);
        if (count($prices) < FeatureEngineer::MIN_BARS) {
            return null;
        }

        $events = $this->eventsIndex()[$stock->id] ?? [];
        $features = FeatureEngineer::compute($prices, $macro, $events, [
            'market_cap' => $stock->market_cap,
        ]);
        if ($features === null) {
            return null;
        }

        $closes = array_column($prices, 'close');
        $volumes = array_column($prices, 'volume');
        $last = count($closes) - 1;
        $avgVol20 = array_sum(array_slice($volumes, max(0, $last - 19), min(20, $last + 1))) / min(20, $last + 1);

        $features['_price'] = (float) $closes[$last];
        $features['_avg_volume'] = (float) $avgVol20;

        return $features;
    }

    /** @return array<string, array<string, mixed>> */
    private function macroIndex(string $date): array
    {
        static $cache = [];
        if (! isset($cache[$date])) {
            $lookback = (int) config('listikhar.model.lookback_days');
            $rows = DB::table('macro_environment')
                ->where('observation_date', '>=', date('Y-m-d', strtotime($date.' -'.($lookback + 90).' days')))
                ->where('observation_date', '<=', $date)
                ->orderBy('observation_date')
                ->get();
            $index = [];
            foreach ($rows as $row) {
                $index[FeatureEngineer::dateKey($row->observation_date)] = (array) $row;
            }
            $cache[$date] = $index;
        }

        return $cache[$date];
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function eventsIndex(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $rows = DB::table('corporate_events')
                ->where('event_date', '>=', date('Y-m-d', strtotime('-1 year')))
                ->get(['stock_id', 'event_date', 'event_type']);
            foreach ($rows as $row) {
                $cache[$row->stock_id][] = [
                    'event_date' => $row->event_date,
                    'event_type' => $row->event_type,
                ];
            }
        }

        return $cache;
    }

    /**
     * Human-facing snapshot of the strongest drivers for this pick.
     *
     * @param  array<string, float>  $features
     * @param  array<string, float>  $contributions
     * @return array<string, float>
     */
    private function keyFeatures(array $features, array $contributions): array
    {
        arsort($contributions);

        $keys = [
            'ret_20d', 'ret_5d', 'rel_volume', 'volume_ratio_5_20', 'trend_slope_20',
            'fed_trend_63', 'russell2000_ret_20', 'vix_change_10', 'vix_level',
        ];

        $out = [];
        $added = 0;
        foreach (array_keys($contributions) as $name) {
            if (in_array($name, $keys, true) && isset($features[$name])) {
                $out[$name] = round($features[$name], 4);
                if (++$added >= 5) {
                    break;
                }
            }
        }

        return $out;
    }
}
