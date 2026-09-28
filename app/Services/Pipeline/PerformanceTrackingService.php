<?php

namespace App\Services\Pipeline;

use App\Models\DailyRecommendation;
use Illuminate\Support\Facades\DB;

/**
 * Tracks the ongoing performance of every recommendation: daily close,
 * % change since recommendation, and the benchmark (Russell 2000) change
 * over the same window. Data accumulates for periodic retraining/evaluation.
 */
class PerformanceTrackingService
{
    /**
     * @return array<string, int>
     */
    public function update(): array
    {
        $benchmarkSymbol = config('listikhar.benchmark_symbol', '^RUT');

        $macro = DB::table('macro_environment')
            ->orderBy('observation_date')
            ->get(['observation_date', 'russell2000_close']);
        $benchmarkByDate = [];
        foreach ($macro as $row) {
            if ($row->russell2000_close !== null) {
                $benchmarkByDate[$row->observation_date] = (float) $row->russell2000_close;
            }
        }

        $recommendations = DailyRecommendation::with('stock')
            ->orderBy('recommendation_date')
            ->get();

        $inserted = 0;
        $updated = 0;

        foreach ($recommendations as $rec) {
            $recDate = $rec->recommendation_date->format('Y-m-d');
            $entryPrice = (float) $rec->price_at_recommendation;
            if ($entryPrice <= 0) {
                continue;
            }

            $prices = DB::table('stock_prices')
                ->where('stock_id', $rec->stock_id)
                ->where('trade_date', '>', $recDate)
                ->orderBy('trade_date')
                ->get(['trade_date', 'close']);

            if ($prices->isEmpty()) {
                continue;
            }

            $benchmarkEntry = $benchmarkByDate[$this->previousBenchmarkDate($benchmarkByDate, $recDate)]
                ?? $benchmarkByDate[$recDate]
                ?? null;

            foreach ($prices as $bar) {
                $close = (float) $bar->close;
                $pct = ($close / $entryPrice - 1) * 100;

                $benchPct = null;
                $benchNow = $benchmarkByDate[$bar->trade_date] ?? null;
                if ($benchmarkEntry !== null && $benchNow !== null) {
                    $benchPct = ($benchNow / $benchmarkEntry - 1) * 100;
                }

                $exists = DB::table('stock_performance_tracking')
                    ->where('recommendation_id', $rec->id)
                    ->where('track_date', $bar->trade_date)
                    ->exists();

                if ($exists) {
                    DB::table('stock_performance_tracking')
                        ->where('recommendation_id', $rec->id)
                        ->where('track_date', $bar->trade_date)
                        ->update([
                            'close_price' => $close,
                            'pct_change_since_recommendation' => round($pct, 3),
                            'benchmark_pct_change' => $benchPct !== null ? round($benchPct, 3) : null,
                            'updated_at' => now(),
                        ]);
                    $updated++;
                } else {
                    DB::table('stock_performance_tracking')->insert([
                        'recommendation_id' => $rec->id,
                        'stock_id' => $rec->stock_id,
                        'track_date' => $bar->trade_date,
                        'close_price' => $close,
                        'pct_change_since_recommendation' => round($pct, 3),
                        'benchmark_pct_change' => $benchPct !== null ? round($benchPct, 3) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $inserted++;
                }
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'recommendations' => $recommendations->count()];
    }

    /**
     * @param  array<string, float>  $benchmarkByDate
     */
    private function previousBenchmarkDate(array $benchmarkByDate, string $date): string
    {
        $keys = array_keys($benchmarkByDate);
        $prior = null;
        foreach ($keys as $key) {
            if ($key <= $date) {
                $prior = $key;
            } else {
                break;
            }
        }

        return $prior ?? $date;
    }
}
