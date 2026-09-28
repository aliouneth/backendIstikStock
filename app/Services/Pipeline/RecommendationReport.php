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
        $rows = DB::table('stock_performance_tracking as t')
            ->join('daily_recommendations as r', 'r.id', '=', 't.recommendation_id')
            ->select(
                't.track_date',
                't.pct_change_since_recommendation as pct',
                't.benchmark_pct_change as bench',
                'r.recommendation_date'
            )
            ->whereNotNull('t.pct_change_since_recommendation')
            ->get();

        if ($rows->isEmpty()) {
            return ['tracked_rows' => 0, 'message' => 'No tracked performance yet.'];
        }

        // Latest observation per recommendation = current standing of each pick.
        $byRec = [];
        foreach ($rows as $row) {
            $byRec[$row->recommendation_date][] = $row;
        }

        $returns = [];
        $bench = [];
        foreach ($byRec as $recRows) {
            $last = end($recRows);
            $returns[] = (float) $last->pct;
            if ($last->bench !== null) {
                $bench[] = (float) $last->bench;
            }
        }

        $hit = count(array_filter($returns, fn ($r) => $r > 0));
        $avg = array_sum($returns) / count($returns);
        $avgBench = $bench !== [] ? array_sum($bench) / count($bench) : null;

        return [
            'recommendations_evaluated' => count($returns),
            'tracked_rows' => $rows->count(),
            'avg_pick_return_pct' => round($avg, 2),
            'avg_benchmark_return_pct' => $avgBench !== null ? round($avgBench, 2) : null,
            'avg_excess_pct' => $avgBench !== null ? round($avg - $avgBench, 2) : null,
            'hit_rate_pct' => round(100 * $hit / count($returns), 1),
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
            'metrics' => $meta['metrics'] ?? null,
            'dataset' => $meta['dataset'] ?? null,
            'top_features' => array_slice($importance, 0, 8, true),
        ];
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
            'ticker' => $rec->stock->ticker ?? '?',
            'name' => $rec->stock->name ?? null,
            'sector' => $rec->stock->sector ?? null,
            'price_at_recommendation' => (float) $rec->price_at_recommendation,
            'score' => (float) $rec->model_score,
            'rationale' => $rec->rationale,
            'key_features' => $rec->features ?? [],
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
