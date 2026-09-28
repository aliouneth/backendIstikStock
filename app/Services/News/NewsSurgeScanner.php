<?php

namespace App\Services\News;

use App\Services\Ml\ScoringService;
use Illuminate\Support\Facades\Log;

/**
 * News surge scan: intersection of the model's strongest candidates with
 * fresh, catalyst-positive headlines → top-N stocks likely to jump within
 * the next few trading days, each with reasoning and a target sell price.
 *
 * surge score = 50% model score (trained 20-day return rank) + 50% news
 * score (catalyst keywords × recency), plus a small bonus for headlines
 * younger than 12 hours. Target price = max(model short-horizon expectation,
 * +5%) plus a catalyst lift (news_score × 0.12), capped at +35%.
 */
class NewsSurgeScanner
{
    public function __construct(
        private readonly ScoringService $scoring,
        private readonly NewsClient $news,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(int $topN = 0): array
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $started = microtime(true);
        $cfg = config('listikhar.news');
        $topN = $topN > 0 ? $topN : (int) $cfg['max_results'];
        $lookback = (int) $cfg['lookback_days'];
        $horizon = (int) $cfg['horizon_days'];

        // 1) Model's strongest candidates (ordered by predicted 20d return).
        $scored = $this->scoring->topPicks((int) $cfg['candidates']);
        $candidates = $scored['items'];

        // 2) Fresh news per candidate (batched fetch).
        $newsMap = $this->news->headlinesMany(
            array_column($candidates, 'ticker'),
            $lookback,
        );

        $withNews = [];
        foreach ($candidates as $index => $item) {
            $headlines = $newsMap[$item['ticker']] ?? [];
            if ($headlines === []) {
                continue;
            }

            $sentiment = HeadlineScorer::score($headlines);

            $withNews[] = [
                'item' => $item,
                'model_rank' => $index + 1,
                'headlines' => $headlines,
                'sentiment' => $sentiment,
                'news_score' => $sentiment['score'],
            ];
        }

        // 3) Combine model + news into the surge score.
        foreach ($withNews as &$row) {
            $surge = 0.5 * (float) $row['item']['score'] + 0.5 * (float) $row['news_score'];

            $best = $row['sentiment']['best_index'];
            if ($best !== null && $row['headlines'][$best]['age_hours'] <= 12) {
                $surge += 3.0;
            }

            $row['surge'] = round(min(100.0, $surge), 2);
        }
        unset($row);

        usort($withNews, fn ($a, $b) => $b['surge'] <=> $a['surge']);
        $top = array_slice($withNews, 0, $topN);

        // 4) Targets + reasoning.
        $items = [];
        foreach ($top as $rank => $row) {
            $items[] = $this->present($rank + 1, $row, $horizon, $lookback, count($candidates));
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'date' => $scored['date'],
            'lookback_days' => $lookback,
            'horizon_days' => $horizon,
            'candidates_scanned' => count($candidates),
            'with_fresh_news' => count($withNews),
            'took_seconds' => round(microtime(true) - $started, 1),
            'items' => $items,
        ];

        Log::info('News surge scan complete', [
            'candidates' => count($candidates),
            'with_news' => count($withNews),
            'returned' => count($items),
        ]);

        return $payload;
    }

    /**
     * @param  array{item: array<string, mixed>, model_rank: int, headlines: list<array<string, mixed>>, sentiment: array<string, mixed>, news_score: int, surge: float}  $row
     * @return array<string, mixed>
     */
    private function present(int $rank, array $row, int $horizon, int $lookback, int $candidates): array
    {
        $item = $row['item'];
        $price = (float) $item['price'];

        // Model expectation → short-horizon target, lifted by catalyst
        // strength: targets land in the +5% … +35% band.
        $logReturn20d = (float) $item['raw_score'];
        $logReturnHorizon = $logReturn20d * sqrt($horizon / 20);
        $expectedPct = (exp($logReturnHorizon) - 1) * 100;
        $newsScore = (float) $row['news_score'];
        $targetPct = min(35.0, max($expectedPct, 5.0) + $newsScore * 0.12);
        $targetPrice = round($price * (1 + $targetPct / 100), 4);

        $signals = $row['sentiment']['signals'];
        $risks = $row['sentiment']['risks'];
        $best = $row['sentiment']['best_index'];
        $freshCount = count($row['headlines']);

        $reasoning = sprintf(
            '%d fresh headline%s (last %d days) — catalysts: %s. Model ranks it #%d of %d strongest candidates, expecting %+.1f%% over 20 days. Sell target $%s (+%.1f%%) within ~%d trading days.',
            $freshCount,
            $freshCount === 1 ? '' : 's',
            $lookback,
            $signals !== [] ? implode(', ', $signals) : 'general coverage',
            $row['model_rank'],
            $candidates,
            ((exp($logReturn20d) - 1) * 100),
            $this->fmtPrice($targetPrice),
            $targetPct,
            $horizon,
        );

        if ($best !== null) {
            $reasoning .= sprintf(
                ' Lead story: "%s" (%s, %.0fh ago).',
                $row['headlines'][$best]['title'],
                $row['headlines'][$best]['publisher'] ?: 'wire',
                $row['headlines'][$best]['age_hours'],
            );
        }

        if ($risks !== []) {
            $reasoning .= ' Risk flags: '.implode(', ', $risks).'.';
        }

        return [
            'rank' => $rank,
            'ticker' => $item['ticker'],
            'name' => $item['name'],
            'sector' => $item['sector'],
            'price' => $price,
            'surge_score' => $row['surge'],
            'model_score' => round((float) $item['score'], 2),
            'news_score' => $row['news_score'],
            'target_price' => $targetPrice,
            'target_pct' => round($targetPct, 1),
            'horizon_days' => $horizon,
            'reasoning' => $reasoning,
            'key_features' => $item['key_features'],
            'headlines' => array_map(fn (array $h, int $i) => [
                'title' => $h['title'],
                'publisher' => $h['publisher'],
                'url' => $h['url'],
                'age_hours' => $h['age_hours'],
                'signal' => $i === $row['sentiment']['best_index']
                    ? 'best'
                    : HeadlineScorer::classify($h['title']),
            ], $row['headlines'], array_keys($row['headlines'])),
        ];
    }

    private function fmtPrice(float $price): string
    {
        return rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.');
    }
}
