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
 * - Label      = forward `horizon_days` log return (what preceded a strong run
 *                is learned from the positive tail of this distribution).
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

            $meta = [
                'metrics' => ['holdout' => $holdout, 'train' => $inSample],
                'fit' => $fit,
                'dataset' => $dataset['stats'],
                'hyperparameters' => array_intersect_key(
                    $this->repo->makeRegressor()->toArray()['params'] ?? [],
                    array_flip(['n_estimators', 'learning_rate', 'max_depth', 'min_child_samples', 'subsample', 'bins', 'max_samples', 'horizon_days'])
                ),
                'label' => 'forward_'.$dataset['stats']['horizon_days'].'d_log_return',
                'split' => 'chronological_85_15',
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
     *   X_test: array<string, list<float>>, y_test: list<float>,
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

        // --- Stocks -------------------------------------------------------
        $stocks = DB::table('stocks')
            ->join('stock_prices', 'stocks.id', '=', 'stock_prices.stock_id')
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
        $eventRows = DB::table('corporate_events')->get(['stock_id', 'event_date', 'event_type']);
        foreach ($eventRows as $row) {
            $eventsByStock[$row->stock_id][] = [
                'event_date' => $row->event_date,
                'event_type' => $row->event_type,
            ];
        }

        $perStockTrain = (int) floor($maxSamples * 0.85 / count($stocks));
        $perStockTest = (int) max(1, ceil($maxSamples * 0.15 / count($stocks)));
        $perStockTrain = max(5, $perStockTrain);

        $X_train = $X_test = [];
        foreach (FeatureEngineer::NAMES as $name) {
            $X_train[$name] = [];
            $X_test[$name] = [];
        }
        $y_train = [];
        $y_test = [];

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
                    $trainDays[] = $i;
                } else {
                    $testDays[] = $i;
                }
            }

            mt_srand($seed + (int) $stock->id);
            $trainPick = $this->pick($trainDays, $perStockTrain);
            $testPick = $this->pick($testDays, $perStockTest);

            $events = $eventsByStock[$stock->id] ?? [];
            $meta = ['market_cap' => $stock->market_cap];

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
            'stats' => [
                'stocks' => count($stocks),
                'stocks_skipped' => $skipCount,
                'train_samples' => $trainCount,
                'test_samples' => $testCount,
                'test_start_date' => $testStart,
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
     * @return array{features: array<string, float>, label: float}|null
     */
    private function sampleAt(array $prices, array $macro, array $events, array $meta, int $i, int $lookback, int $horizon): ?array
    {
        $start = max(0, $i - $lookback + 1);
        $windowPrices = array_slice($prices, $start, $i - $start + 1);
        $windowMacro = array_slice($macro, $start, $i - $start + 1);

        $features = FeatureEngineer::compute($windowPrices, $windowMacro, $events, $meta);
        if ($features === null) {
            return null;
        }

        $entry = (float) $prices[$i]['close'];
        $exit = (float) $prices[$i + $horizon]['close'];
        if ($entry <= 0 || $exit <= 0) {
            return null;
        }

        return [
            'features' => $features,
            'label' => log($exit / $entry),
        ];
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
