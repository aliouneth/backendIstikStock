<?php

namespace App\Services\Pipeline;

use App\Models\DailyRecommendation;
use App\Services\Ml\ModelRepository;
use Illuminate\Support\Facades\DB;

/**
 * Read-model / evaluation layer: today's picks, historical recommendations
 * with their tracked performance, and aggregate model evaluation versus the
 * benchmark.
 */
class RecommendationReport
{
    public function __construct(private readonly ModelRepository $repo) {}

    /** @return array<string, mixed>|null */
    public function today(): ?array
    {
        $latest = DailyRecommendation::max('recommendation_date');
        if ($latest === null) {
            return null;
        }

        return $this->forDate($latest);
    }

    /** @return array<string, mixed>|null */
    public function forDate(string $date): ?array
    {
        $recs = DailyRecommendation::with('stock')
            ->where('recommendation_date', $date)
            ->orderBy('rank')
            ->get();
        if ($recs->isEmpty()) {
            return null;
        }

        return [
            'date' => $date,
            'model_version' => $recs->first()->model_version,
            'model' => $this->modelInfo(),
            'items' => $recs->map(fn ($rec) => $this->presentRec($rec))->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $days = 30): array
    {
        $since = now()->subDays($days)->format('Y-m-d');

        $recs = DailyRecommendation::with('stock')
            ->where('recommendation_date', '>=', $since)
            ->orderByDesc('recommendation_date')
            ->orderBy('rank')
            ->get();

        $grouped = [];
        foreach ($recs as $rec) {
            $key = $rec->recommendation_date->format('Y-m-d');
            $grouped[$key][] = $rec;
        }
        ksort($grouped);
        krsort($grouped);

        $out = [];
        foreach ($grouped as $date => $items) {
            $picks = [];
            $lastPcts = [];
            $benchPcts = [];

            foreach ($items as $rec) {
                $present = $this->presentRec($rec);
                $picks[] = $present;

                if ($present['performance']['last_pct'] !== null) {
                    $lastPcts[] = $present['performance']['last_pct'];
                }
                if ($present['performance']['last_benchmark_pct'] !== null) {
                    $benchPcts[] = $present['performance']['last_benchmark_pct'];
                }
            }

            $out[] = [
                'date' => $date,
                'picks' => count($picks),
                'avg_return_pct' => $lastPcts !== [] ? round(array_sum($lastPcts) / count($lastPcts), 2) : null,
                'avg_benchmark_pct' => $benchPcts !== [] ? round(array_sum($benchPcts) / count($benchPcts), 2) : null,
                'items' => $picks,
            ];
        }

        return $out;
    }

    /**
     * Aggregate evaluation: average tracked return of picks vs benchmark.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $recommendations = DailyRecommendation::query()
            ->orderByDesc('recommendation_date')
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'stock_id', 'recommendation_date', 'entry_price', 'rank']);

        if ($recommendations->isEmpty()) {
            return ['tracked_rows' => 0, 'recommendations_evaluated' => 0, 'message' => 'No recommendations to evaluate.'];
        }

        $benchmarkByDate = DB::table('macro_environment')
            ->whereNotNull('russell2000_close')
            ->orderBy('observation_date')
            ->pluck('russell2000_close', 'observation_date')
            ->map(fn ($value): float => (float) $value)
            ->all();
        $horizons = [1, 5, 20];
        $outcomes = array_fill_keys($horizons, []);

        foreach ($recommendations as $recommendation) {
            $recommendationDate = $recommendation->recommendation_date->format('Y-m-d');
            $bars = DB::table('stock_prices')
                ->where('stock_id', $recommendation->stock_id)
                ->where('trade_date', '>', $recommendationDate)
                ->orderBy('trade_date')
                ->limit(max($horizons))
                ->get(['trade_date', 'open', 'high', 'low', 'close']);

            if ($bars->isEmpty()) {
                continue;
            }

            $entry = $recommendation->entry_price !== null
                ? (float) $recommendation->entry_price
                : (float) $bars->first()->open;
            if ($entry <= 0) {
                continue;
            }

            $benchmarkEntry = $benchmarkByDate[$recommendationDate] ?? null;
            foreach ($horizons as $horizon) {
                if ($bars->count() < $horizon) {
                    continue;
                }

                $window = $bars->take($horizon);
                $last = $window->last();
                $highest = (float) $window->max('high');
                $lowest = (float) $window->min('low');
                $benchmarkExit = $benchmarkByDate[$last->trade_date] ?? null;
                $closeReturn = ((float) $last->close / $entry - 1) * 100;
                $outcomes[$horizon][] = [
                    'close' => $closeReturn,
                    'mfe' => ($highest / $entry - 1) * 100,
                    'adverse' => ($lowest / $entry - 1) * 100,
                    'benchmark' => $benchmarkEntry !== null && $benchmarkExit !== null
                        ? ($benchmarkExit / $benchmarkEntry - 1) * 100
                        : null,
                ];
            }
        }

        $summary = [];
        foreach ($horizons as $horizon) {
            $rows = $outcomes[$horizon];
            $returns = array_column($rows, 'close');
            $benchmark = array_values(array_filter(array_column($rows, 'benchmark'), fn ($value): bool => $value !== null));
            $average = $returns !== [] ? array_sum($returns) / count($returns) : null;
            $averageBenchmark = $benchmark !== [] ? array_sum($benchmark) / count($benchmark) : null;
            $summary[$horizon] = [
                'recommendations_evaluated' => count($rows),
                'avg_close_return_pct' => $average !== null ? round($average, 2) : null,
                'median_close_return_pct' => $returns !== [] ? round($this->median($returns), 2) : null,
                'positive_close_rate_pct' => $returns !== []
                    ? round(100 * count(array_filter($returns, fn (float $value): bool => $value > 0)) / count($returns), 1)
                    : null,
                'avg_mfe_pct' => $rows !== [] ? round(array_sum(array_column($rows, 'mfe')) / count($rows), 2) : null,
                'mfe_10pct_rate_pct' => $rows !== []
                    ? round(100 * count(array_filter($rows, fn (array $row): bool => $row['mfe'] >= 10)) / count($rows), 1)
                    : null,
                'avg_adverse_pct' => $rows !== [] ? round(array_sum(array_column($rows, 'adverse')) / count($rows), 2) : null,
                'avg_benchmark_return_pct' => $averageBenchmark !== null ? round($averageBenchmark, 2) : null,
                'avg_excess_pct' => $average !== null && $averageBenchmark !== null
                    ? round($average - $averageBenchmark, 2)
                    : null,
            ];
        }

        $oneSession = $summary[1];

        return [
            'recommendations_evaluated' => $oneSession['recommendations_evaluated'],
            'tracked_rows' => (int) DB::table('stock_performance_tracking')->count(),
            'avg_pick_return_pct' => $oneSession['avg_close_return_pct'],
            'median_pick_return_pct' => $oneSession['median_close_return_pct'],
            'avg_benchmark_return_pct' => $oneSession['avg_benchmark_return_pct'],
            'avg_excess_pct' => $oneSession['avg_excess_pct'],
            'hit_rate_pct' => $oneSession['positive_close_rate_pct'],
            'horizons' => $summary,
            'entry_convention' => 'next_session_open',
        ];
    }

    /** @return array<string, mixed> */
    public function modelInfo(): array
    {
        $meta = $this->repo->meta();
        if ($meta === null) {
            return ['trained' => false];
        }

        $importance = $meta['importance'] ?? [];
        arsort($importance);

        return [
            'trained' => true,
            'version' => $meta['version'] ?? null,
            'trained_at' => $meta['trained_at'] ?? null,
            'target' => $meta['target'] ?? null,
            'entry' => $meta['entry'] ?? null,
            'metrics' => $meta['metrics'] ?? null,
            'dataset' => $meta['dataset'] ?? null,
            'top_features' => array_slice($importance, 0, 8, true),
        ];
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRec(DailyRecommendation $rec): array
    {
        $perfRows = $rec->performance()->orderBy('track_date')->get();

        $last = $perfRows->last();
        $best = $perfRows->isEmpty() ? null : $perfRows->max('pct_change_since_recommendation');

        return [
            'rank' => $rec->rank,
            'ticker' => $rec->stock?->ticker ?? '?',
            'name' => $rec->stock?->name ?? null,
            'sector' => $rec->stock?->sector ?? null,
            'price_at_recommendation' => (float) $rec->price_at_recommendation,
            'entry_price' => $rec->entry_price !== null ? (float) $rec->entry_price : null,
            'score' => (float) $rec->model_score,
            'rationale' => $rec->rationale,
            'key_features' => $rec->features ?? [],
            'risks' => $rec->risks ?? [],
            'performance' => [
                'current_price' => $last?->close_price !== null ? (float) $last->close_price : null,
                'last_pct' => $last?->pct_change_since_recommendation !== null
                    ? (float) $last->pct_change_since_recommendation
                    : null,
                'last_benchmark_pct' => $last?->benchmark_pct_change !== null
                    ? (float) $last->benchmark_pct_change
                    : null,
                'best_pct' => $best !== null ? (float) $best : null,
                'days_tracked' => $perfRows->count(),
                'series' => $perfRows->map(fn ($row) => [
                    'date' => $row->track_date->format('Y-m-d'),
                    'close' => (float) $row->close_price,
                    'pct' => $row->pct_change_since_recommendation !== null
                        ? (float) $row->pct_change_since_recommendation
                        : null,
                    'benchmark_pct' => $row->benchmark_pct_change !== null
                        ? (float) $row->benchmark_pct_change
                        : null,
                ])->all(),
            ],
        ];
    }
}
