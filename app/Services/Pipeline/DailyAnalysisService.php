<?php

namespace App\Services\Pipeline;

use App\Models\DailyRecommendation;
use App\Models\ModelRun;
use App\Services\Data\MacroDataService;
use App\Services\Data\MarketDataRefreshService;
use App\Services\Ml\FeatureEngineer;
use App\Services\Ml\ModelRepository;
use App\Services\Ml\ModelTrainer;
use App\Services\Ml\ScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The full daily pipeline:
 *   data refresh → (re)train if stale → feature generation → inference →
 *   persist top-N recommendations → track ongoing performance.
 */
class DailyAnalysisService
{
    public function __construct(
        private readonly ModelRepository $repo,
        private readonly ModelTrainer $trainer,
        private readonly ScoringService $scoring,
        private readonly MarketDataRefreshService $marketRefresh,
        private readonly MacroDataService $macroRefresh,
        private readonly PerformanceTrackingService $tracker,
    ) {}

    /**
     * @param  bool  $refresh  pull live Yahoo data first (falls back gracefully)
     * @return array<string, mixed>
     */
    public function run(bool $refresh = true): array
    {
        set_time_limit(600);
        ignore_user_abort(true);

        $run = ModelRun::create([
            'run_type' => 'inference',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $started = microtime(true);

        try {
            $refreshSummary = [];
            if ($refresh && config('listikhar.enable_live_refresh')) {
                $refreshSummary['market'] = $this->marketRefresh->refresh();
                $refreshSummary['macro'] = $this->macroRefresh->refresh();
            }

            $retrained = false;
            if ($this->repo->isStale()) {
                $this->trainer->train();
                $retrained = true;
            }

            $topN = (int) config('listikhar.top_picks', 5);
            $scored = $this->scoring->topPicks($topN);
            $persisted = $this->persist($scored);
            $tracking = $this->tracker->update();

            $payload = [
                'date' => $scored['date'],
                'model_version' => $scored['model_version'],
                'model_retrained' => $retrained,
                'universe' => $scored['universe'],
                'scored' => $scored['scored'],
                'skipped' => $scored['skipped'],
                'refresh' => $refreshSummary,
                'tracking' => $tracking,
                'took_seconds' => round(microtime(true) - $started, 2),
                'recommendations' => $persisted,
            ];

            $run->succeed([
                'date' => $scored['date'],
                'model_version' => $scored['model_version'],
                'scored' => $scored['scored'],
                'took_seconds' => $payload['took_seconds'],
            ], 'top '.count($persisted).' persisted');

            Log::info('Daily analysis complete', [
                'date' => $scored['date'],
                'picks' => array_column($persisted, 'ticker'),
            ]);

            return $payload;
        } catch (Throwable $e) {
            $run->fail($e->getMessage());
            Log::error('Daily analysis failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Score+persist recommendations for each of the last $days trading days
     * that do not have recommendations yet, then refresh tracking. Used to
     * populate the history view and build the feedback dataset.
     *
     * @return array<string, mixed>
     */
    public function backfill(int $days = 15): array
    {
        set_time_limit(600);

        $dates = DB::table('macro_environment')
            ->where('observation_date', '<=', DB::table('stock_prices')->max('trade_date'))
            ->orderByDesc('observation_date')
            ->limit($days)
            ->pluck('observation_date')
            ->map(fn ($d) => FeatureEngineer::dateKey($d))
            ->sort()
            ->values()
            ->all();

        $topN = (int) config('listikhar.top_picks', 5);
        $created = [];

        foreach ($dates as $date) {
            $exists = DailyRecommendation::where('recommendation_date', $date)->exists();
            if ($exists) {
                continue;
            }

            $scored = $this->scoring->topPicks($topN, $date);
            $persisted = $this->persist($scored);
            $created[] = ['date' => $date, 'picks' => array_column($persisted, 'ticker')];
        }

        $tracking = $this->tracker->update();

        return ['backfilled_dates' => count($created), 'dates' => $created, 'tracking' => $tracking];
    }

    /**
     * @param  array<string, mixed>  $scored
     * @return list<array<string, mixed>>
     */
    private function persist(array $scored): array
    {
        $modelVersion = $scored['model_version'];
        $date = $scored['date'];

        return DB::transaction(function () use ($scored, $modelVersion, $date) {
            DailyRecommendation::where('recommendation_date', $date)->delete();

            $out = [];
            foreach ($scored['items'] as $item) {
                DailyRecommendation::create([
                    'recommendation_date' => $date,
                    'stock_id' => $item['stock_id'],
                    'rank' => $item['rank'],
                    'model_score' => $item['score'],
                    'price_at_recommendation' => $item['price'],
                    'rationale' => $item['rationale'],
                    'features' => $item['key_features'],
                    'risks' => $item['risks'] ?? [],
                    'model_version' => $modelVersion,
                ]);

                $out[] = [
                    'rank' => $item['rank'],
                    'ticker' => $item['ticker'],
                    'name' => $item['name'],
                    'sector' => $item['sector'],
                    'price' => $item['price'],
                    'score' => $item['score'],
                    'rationale' => $item['rationale'],
                    'key_features' => $item['key_features'],
                    'risks' => $item['risks'] ?? [],
                ];
            }

            return $out;
        });
    }
}
