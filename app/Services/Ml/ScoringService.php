<?php

namespace App\Services\Ml;

use App\Models\Stock;
use App\Services\Data\UsPennyUniverseService;
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
        $model = $this->repo->loadOrFail();

        $date = $asOf ?? FeatureEngineer::dateKey(
            DB::table('stock_prices')->max('trade_date')
        );
        if (! $date) {
            throw new \RuntimeException('No price data available.');
        }

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
                'stock' => $stock,
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
        $top = array_slice($scored, 0, $topN);

        // Resolve real sector/industry for picks not enriched yet (one
        // keyless Nasdaq request per ticker, cached on the stocks row).
        $toEnrich = [];
        foreach ($top as $row) {
            $sector = $row['stock']->sector;
            if ($sector === null || $sector === '' || $sector === 'Unclassified') {
                $toEnrich[] = $row['stock']->ticker;
            }
        }
        if ($toEnrich !== []) {
            $this->universe->enrichSectors($toEnrich);
            $freshSectors = Stock::whereIn('ticker', $toEnrich)->pluck('sector', 'ticker');
            foreach ($top as &$row) {
                $fresh = $freshSectors[$row['stock']->ticker] ?? null;
                if ($fresh !== null && $fresh !== '') {
                    $row['stock']->sector = $fresh;
                }
            }
            unset($row);
        }

        $items = [];
        foreach ($top as $rank => $row) {
            $contributions = $model->explainRow($row['features']);
            $sector = $row['stock']->sector ?: 'Unclassified';
            $items[] = [
                'rank' => $rank + 1,
                'stock_id' => $row['stock']->id,
                'ticker' => $row['stock']->ticker,
                'name' => $row['stock']->name,
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

        return [
            'date' => $date,
            'universe' => $universe,
            'scored' => count($scored),
            'skipped' => $skipped,
            'model_version' => $model->version(),
            'items' => $items,
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
