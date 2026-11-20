<?php

namespace App\Services\Ml;

use App\Models\ModelRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the supervised-learning dataset out of the stored historical penny
 * stock bars + macro environment and trains the gradient boosting model.
 *
 * - Each sample = one stock at one day.
 * - Label      = +1 for a +10% next-session excursion without a -10% adverse
 *                excursion, -1 for the adverse threshold, otherwise 0.
 * - Split      = chronological (last 15% of dates held out) so reported
 *                metrics are honest for a time-series problem.
 * - Sampling   = capped per config(model.max_samples), spread evenly across
 *                stocks and time to keep PHP training fast.
 */
class ModelTrainer
{
    public function __construct(private readonly ModelRepository $repo) {}

    /**
     * @param  callable(int, int): void|null  $progress
     * @return array<string, mixed>
     */
    public function train(?callable $progress = null): array
    {
        $run = ModelRun::create([
            'run_type' => 'train',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $dataset = $this->buildDataset();

            $model = $this->repo->makeRegressor();
            $fit = $model->train($dataset['X_train'], $dataset['y_train'], $progress);

            $holdout = $model->evaluate($dataset['X_test'], $dataset['y_test']);
            $inSample = $model->evaluate($dataset['X_train'], $dataset['y_train']);
            $holdoutRanking = $this->rankedHoldoutMetrics(
                $model->predictMatrix($dataset['X_test']),
                $dataset['y_test'],
                $dataset['test_dates'],
                $dataset['X_test']
            );

            $meta = [
                'metrics' => ['holdout' => $holdout, 'holdout_ranking' => $holdoutRanking, 'train' => $inSample],
                'fit' => $fit,
                'dataset' => $dataset['stats'],
                'hyperparameters' => array_intersect_key(
                    $this->repo->makeRegressor()->toArray()['params'] ?? [],
                    array_flip(['n_estimators', 'learning_rate', 'max_depth', 'min_child_samples', 'subsample', 'bins', 'max_samples', 'horizon_days'])
                ),
                'label' => 'next_open_clean_runner_outcome_'.$dataset['stats']['horizon_days'].'d',
                'target' => config('listikhar.model.target'),
                'runner_mfe_threshold' => config('listikhar.model.runner_mfe_threshold'),
                'runner_max_adverse_pct' => config('listikhar.model.runner_max_adverse_pct'),
                'entry' => 'next_session_open',
                'universe_filters' => [
                    'min_price' => (float) config('listikhar.penny_min_price'),
                    'max_price' => (float) config('listikhar.penny_max_price'),
                    'min_avg_volume' => (int) config('listikhar.min_avg_volume'),
                    'min_avg_dollar_volume' => (float) config('listikhar.min_avg_dollar_volume'),
                ],
                'split' => 'chronological_85_15_purged_by_label_horizon',
            ];

            $this->repo->save($model, $meta);

            $run->succeed($meta, sprintf(
                'holdout spearman=%.4f rmse=%.4f n=%d',
                $holdout['spearman'] ?? 0,
                $holdout['rmse'] ?? 0,
                $holdout['n'] ?? 0
            ));

            Log::info('Listikhar model trained', [
                'version' => $model->version(),
                'holdout' => $holdout,
            ]);

            return array_merge($meta, ['version' => $model->version(), 'trained_at' => $model->trainedAt()]);
        } catch (Throwable $e) {
            $run->fail($e->getMessage());
            Log::error('Listikhar training failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @return array{
     *   X_train: array<string, list<float>>, y_train: list<float>,
     *   X_test: array<string, list<float>>, y_test: list<float>, test_dates: list<string>,
     *   stats: array<string, mixed>
     * }
     */
    private function buildDataset(): array
    {
        $cfg = config('listikhar.model');
        $horizon = (int) $cfg['horizon_days'];
        $maxSamples = (int) $cfg['max_samples'];
        $lookback = (int) $cfg['lookback_days'];
        $seed = (int) $cfg['random_seed'];
        $minBars = FeatureEngineer::MIN_BARS;

        // --- Macro series -------------------------------------------------
        $macroRows = DB::table('macro_environment')->orderBy('observation_date')->get()
            ->map(fn ($row) => (array) $row)->all();
        if (count($macroRows) < $minBars + $horizon) {
            throw new \RuntimeException('Not enough macro data. Run: php artisan listikhar:seed-historical');
        }
        $macroByDate = [];
        foreach ($macroRows as $row) {
            $macroByDate[FeatureEngineer::dateKey($row['observation_date'])] = $row;
        }

        // --- Chronological split point (last 15% of trading days) ---------
        $allDates = array_map(
            fn ($r) => FeatureEngineer::dateKey($r['observation_date']),
            $macroRows
        );
        $testStart = $allDates[(int) floor(count($allDates) * 0.85)];
        $testPeriodDates = array_values(array_filter($allDates, fn (string $date): bool => $date >= $testStart));
        $testDateSet = $this->spreadDates($testPeriodDates, 20);

        // --- Stocks -------------------------------------------------------
        $stocks = DB::table('stocks')
            ->join('stock_prices', 'stocks.id', '=', 'stock_prices.stock_id')
            ->whereIn('stocks.source', ['yahoo', 'csv'])
            ->groupBy('stocks.id')
            ->select('stocks.id', 'stocks.ticker', 'stocks.market_cap')
            ->havingRaw('COUNT(*) >= ?', [$minBars + 5])
            ->get()
            ->all();

        if ($stocks === []) {
            throw new \RuntimeException('No stocks with enough price history. Seed data first.');
        }

        // --- Corporate events, grouped by stock ---------------------------
        $eventsByStock = [];
        $eventRows = DB::table('corporate_events')->get(['stock_id', 'event_date', 'event_type', 'meta']);
        foreach ($eventRows as $row) {
            $eventsByStock[$row->stock_id][] = [
                'event_date' => $row->event_date,
                'event_type' => $row->event_type,
                'meta' => is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta,
            ];
        }

        $perStockTrain = (int) floor($maxSamples * 0.85 / count($stocks));
        $perStockTrain = max(5, $perStockTrain);

        $X_train = $X_test = [];
        foreach (FeatureEngineer::NAMES as $name) {
            $X_train[$name] = [];
            $X_test[$name] = [];
        }
        $y_train = [];
        $y_test = [];
        $testDates = [];

        $trainCount = 0;
        $testCount = 0;
        $skipCount = 0;

        foreach ($stocks as $idx => $stock) {
            $prices = DB::table('stock_prices')
                ->where('stock_id', $stock->id)
                ->orderBy('trade_date')
                ->get(['trade_date', 'open', 'high', 'low', 'close', 'volume'])
                ->map(fn ($row) => (array) $row)
                ->all();

            [$alignedPrices, $alignedMacro] = FeatureEngineer::align($prices, $macroByDate);
            $n = count($alignedPrices);
            if ($n < $minBars + $horizon) {
                $skipCount++;

                continue;
            }

            // Candidate sample days with an available forward label.
            $candidates = [];
            for ($i = $minBars - 1; $i <= $n - 1 - $horizon; $i++) {
                $candidates[] = $i;
            }
            if ($candidates === []) {
                $skipCount++;

                continue;
            }

            $trainDays = [];
            $testDays = [];
            foreach ($candidates as $i) {
                $key = FeatureEngineer::dateKey($alignedPrices[$i]['date'] ?? $alignedPrices[$i]['trade_date']);
                if ($key < $testStart) {
                    $labelEnd = FeatureEngineer::dateKey(
                        $alignedPrices[$i + $horizon]['date'] ?? $alignedPrices[$i + $horizon]['trade_date']
                    );
                    if ($labelEnd < $testStart) {
                        $trainDays[] = $i;
                    }
                } elseif (isset($testDateSet[$key])) {
                    $testDays[] = $i;
                }
            }

            mt_srand($seed + (int) $stock->id);
            $trainPick = $this->pick($trainDays, $perStockTrain);
            $testPick = $testDays;

            $events = $eventsByStock[$stock->id] ?? [];
            $meta = [];

            foreach ($trainPick as $i) {
                $sample = $this->sampleAt($alignedPrices, $alignedMacro, $events, $meta, $i, $lookback, $horizon);
                if ($sample !== null) {
                    foreach ($sample['features'] as $name => $value) {
                        $X_train[$name][] = $value;
                    }
                    $y_train[] = $sample['label'];
                    $trainCount++;
                }
            }

            foreach ($testPick as $i) {
                $sample = $this->sampleAt($alignedPrices, $alignedMacro, $events, $meta, $i, $lookback, $horizon);
                if ($sample !== null) {
                    foreach ($sample['features'] as $name => $value) {
                        $X_test[$name][] = $value;
                    }
                    $y_test[] = $sample['label'];
                    $testDates[] = $sample['as_of'];
                    $testCount++;
                }
            }

            if (($idx + 1) % 250 === 0) {
                Log::info('Dataset build progress', ['stocks' => $idx + 1, 'of' => count($stocks)]);
            }
        }

        if ($trainCount < 500 || $testCount < 100) {
            throw new \RuntimeException(sprintf(
                'Dataset too small (train=%d, test=%d). Seed the historical dataset first.',
                $trainCount,
                $testCount
            ));
        }

        return [
            'X_train' => $X_train,
            'y_train' => $y_train,
            'X_test' => $X_test,
            'y_test' => $y_test,
            'test_dates' => $testDates,
            'stats' => [
                'stocks' => count($stocks),
                'stocks_skipped' => $skipCount,
                'train_samples' => $trainCount,
                'test_samples' => $testCount,
                'test_start_date' => $testStart,
                'holdout_dates_selected' => count($testDateSet),
                'date_range' => [$allDates[0], $allDates[count($allDates) - 1]],
                'horizon_days' => $horizon,
                'lookback_days' => $lookback,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $prices
     * @param  array<int, array<string, mixed>>  $macro
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, mixed>  $meta
     * @return array{features: array<string, float>, label: float, as_of: string}|null
     */
    private function sampleAt(array $prices, array $macro, array $events, array $meta, int $i, int $lookback, int $horizon): ?array
    {
        $start = max(0, $i - $lookback + 1);
        $windowPrices = array_slice($prices, $start, $i - $start + 1);
        $windowMacro = array_slice($macro, $start, $i - $start + 1);

        $features = FeatureEngineer::compute($windowPrices, $windowMacro, $events, $meta);
        if ($features === null || ! $this->isEligibleSample($prices, $i)) {
            return null;
        }

        $entry = (float) ($prices[$i + 1]['open'] ?? 0.0);
        if ($entry <= 0) {
            return null;
        }

        $maxHigh = 0.0;
        $minLow = INF;
        for ($offset = 1; $offset <= $horizon; $offset++) {
            $maxHigh = max($maxHigh, (float) ($prices[$i + $offset]['high'] ?? $prices[$i + $offset]['close']));
            $minLow = min($minLow, (float) ($prices[$i + $offset]['low'] ?? $prices[$i + $offset]['close']));
        }

        if ($maxHigh <= 0 || $minLow <= 0) {
            return null;
        }

        $favorablePct = $maxHigh / $entry - 1;
        $adversePct = $minLow / $entry - 1;
        $runnerThreshold = (float) config('listikhar.model.runner_mfe_threshold', 0.10);
        $adverseThreshold = (float) config('listikhar.model.runner_max_adverse_pct', 0.10);
        $label = $adversePct <= -$adverseThreshold
            ? -1.0
            : ($favorablePct >= $runnerThreshold ? 1.0 : 0.0);

        return [
            'features' => $features,
            'label' => $label,
            'as_of' => FeatureEngineer::dateKey($prices[$i]['date'] ?? $prices[$i]['trade_date']),
        ];
    }

    /**
     * Measure whether the model ranks the strongest labels within each test date.
     *
     * @param  list<float>  $predictions
     * @param  list<float>  $labels
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function rankedHoldoutMetrics(array $predictions, array $labels, array $dates, array $features): array
    {
        $byDate = [];
        foreach ($labels as $index => $label) {
            $relativeVolume = (float) ($features['rel_volume'][$index] ?? 1.0);
            $dollarVolumeSurge = (float) ($features['dollar_volume_ratio_5_20'][$index] ?? 1.0);
            $gap = (float) ($features['gap_pct'][$index] ?? 0.0);
            $closeLocation = (float) ($features['close_location_1d'][$index] ?? 0.5);
            $byDate[$dates[$index]][] = [
                'model' => $predictions[$index],
                'relative_volume' => $relativeVolume,
                'dollar_volume_surge' => $dollarVolumeSurge,
                'gap' => $gap,
                'gap_volume_rule' => log(max(0.1, $relativeVolume))
                    + 3.0 * max(0.0, $gap)
                    + 0.35 * log(max(0.1, $dollarVolumeSurge))
                    + 0.2 * max(0.0, $closeLocation - 0.5),
                'label' => $label,
            ];
        }

        $rankers = ['model', 'relative_volume', 'dollar_volume_surge', 'gap', 'gap_volume_rule'];
        $dailyResults = array_fill_keys($rankers, []);
        $crossSectionMeans = [];
        $crossSectionRunnerRates = [];
        $crossSectionAdverseRates = [];

        foreach ($byDate as $dateRows) {
            if (count($dateRows) < 5) {
                continue;
            }
            $labelsForDate = array_column($dateRows, 'label');
            $crossSectionMeans[] = array_sum($labelsForDate) / count($labelsForDate);
            $crossSectionRunnerRates[] = count(array_filter($labelsForDate, fn (float $label): bool => $label > 0)) / count($labelsForDate);
            $crossSectionAdverseRates[] = count(array_filter($labelsForDate, fn (float $label): bool => $label < 0)) / count($labelsForDate);

            foreach ($rankers as $ranker) {
                $rows = $dateRows;
                usort($rows, fn (array $left, array $right): int => $right[$ranker] <=> $left[$ranker]);
                $eligibleRows = $ranker === 'model'
                    ? array_values(array_filter(
                        $rows,
                        fn (array $row): bool => $row['model'] > (float) config('listikhar.model.minimum_expected_edge', 0.0)
                    ))
                    : $rows;
                $selectedRows = array_slice($eligibleRows, 0, 5);
                $topFive = array_column($selectedRows, 'label');
                $topDecileCount = max(1, (int) ceil(count($rows) * 0.1));
                $topDecile = array_column(array_slice($rows, 0, $topDecileCount), 'label');
                $dailyResults[$ranker][] = [
                    'top5_target' => $topFive !== [] ? array_sum($topFive) / count($topFive) : 0.0,
                    'top5_runner_rate' => $topFive !== []
                        ? count(array_filter($topFive, fn (float $label): bool => $label > 0)) / count($topFive)
                        : 0.0,
                    'top5_adverse_rate' => $topFive !== []
                        ? count(array_filter($topFive, fn (float $label): bool => $label < 0)) / count($topFive)
                        : 0.0,
                    'top5_positive_score_rate' => count($selectedRows) / 5,
                    'picks_per_day' => count($selectedRows),
                    'top_decile_target' => array_sum($topDecile) / $topDecileCount,
                ];
            }
        }

        $rankerSummary = [];
        foreach ($dailyResults as $ranker => $dateRows) {
            $count = max(1, count($dateRows));
            $rankerSummary[$ranker] = [
                'avg_top5_target' => round(array_sum(array_column($dateRows, 'top5_target')) / $count, 6),
                'top5_runner_rate_pct' => round(100 * array_sum(array_column($dateRows, 'top5_runner_rate')) / $count, 2),
                'top5_adverse_rate_pct' => round(100 * array_sum(array_column($dateRows, 'top5_adverse_rate')) / $count, 2),
                'top5_positive_score_rate_pct' => round(100 * array_sum(array_column($dateRows, 'top5_positive_score_rate')) / $count, 2),
                'avg_selected_picks_per_day' => round(array_sum(array_column($dateRows, 'picks_per_day')) / $count, 2),
                'avg_top_decile_target' => round(array_sum(array_column($dateRows, 'top_decile_target')) / $count, 6),
            ];
        }

        $model = $rankerSummary['model'];
        $crossSectionMean = array_sum($crossSectionMeans) / max(1, count($crossSectionMeans));
        $crossSectionRunnerRate = array_sum($crossSectionRunnerRates) / max(1, count($crossSectionRunnerRates));
        $crossSectionAdverseRate = array_sum($crossSectionAdverseRates) / max(1, count($crossSectionAdverseRates));

        return [
            'dates' => count($crossSectionMeans),
            'rankers' => $rankerSummary,
            'avg_top5_target' => $model['avg_top5_target'],
            'avg_top5_lift' => round($model['avg_top5_target'] - $crossSectionMean, 6),
            'top5_runner_rate_pct' => $model['top5_runner_rate_pct'],
            'top5_adverse_rate_pct' => $model['top5_adverse_rate_pct'],
            'avg_top_decile_target' => $model['avg_top_decile_target'],
            'avg_cross_section_target' => round($crossSectionMean, 6),
            'avg_top_decile_lift' => round($model['avg_top_decile_target'] - $crossSectionMean, 6),
            'cross_section_runner_rate_pct' => round(100 * $crossSectionRunnerRate, 2),
            'cross_section_adverse_rate_pct' => round(100 * $crossSectionAdverseRate, 2),
        ];
    }

    /**
     * Select evenly spaced trading dates so holdout ranking uses a full cross-section per date.
     *
     * @param  list<string>  $dates
     * @return array<string, true>
     */
    private function spreadDates(array $dates, int $desired): array
    {
        $count = count($dates);
        if ($count === 0) {
            return [];
        }

        $sampleCount = min($desired, $count);
        $selected = [];
        for ($i = 0; $i < $sampleCount; $i++) {
            $index = $sampleCount === 1
                ? $count - 1
                : (int) round($i * ($count - 1) / ($sampleCount - 1));
            $selected[$dates[$index]] = true;
        }

        return $selected;
    }

    /**
     * Training rows must use the same penny price and liquidity gates as live inference.
     *
     * @param  array<int, array<string, mixed>>  $prices
     */
    private function isEligibleSample(array $prices, int $index): bool
    {
        $close = (float) $prices[$index]['close'];
        $minPrice = (float) config('listikhar.penny_min_price');
        $maxPrice = (float) config('listikhar.penny_max_price');
        if ($close < $minPrice || $close > $maxPrice) {
            return false;
        }

        $start = max(0, $index - 19);
        $volumeTotal = 0.0;
        $dollarVolumeTotal = 0.0;
        $count = $index - $start + 1;
        for ($i = $start; $i <= $index; $i++) {
            $volume = (float) $prices[$i]['volume'];
            $volumeTotal += $volume;
            $dollarVolumeTotal += (float) $prices[$i]['close'] * $volume;
        }

        return $count > 0
            && ($volumeTotal / $count) >= (int) config('listikhar.min_avg_volume')
            && ($dollarVolumeTotal / $count) >= (float) config('listikhar.min_avg_dollar_volume', 250_000);
    }

    /**
     * Deterministically pick up to $want indices from $pool.
     *
     * @param  list<int>  $pool
     * @return list<int>
     */
    private function pick(array $pool, int $want): array
    {
        if (count($pool) <= $want) {
            return $pool;
        }

        // Partial Fisher-Yates shuffle for an unbiased sample.
        for ($i = 0; $i < $want; $i++) {
            $j = mt_rand($i, count($pool) - 1);
            $tmp = $pool[$i];
            $pool[$i] = $pool[$j];
            $pool[$j] = $tmp;
        }

        return array_slice($pool, 0, $want);
    }
}
