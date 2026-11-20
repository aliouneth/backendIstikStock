<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\Ml\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GrowthPotentialController extends Controller
{
    public function __construct(private readonly ScoringService $scoring) {}

    public function show(Request $request, string $ticker): JsonResponse
    {
        $ticker = mb_strtoupper(trim($ticker));

        if (! preg_match('/^[A-Z0-9.\-]{1,12}$/', $ticker)) {
            return response()->json(['message' => 'That does not look like a ticker symbol.'], 422);
        }

        try {
            $result = $this->scoring->evaluateSymbol($ticker, $request->query('date'));
        } catch (RuntimeException $e) {
            $message = $e->getMessage();

            if (str_starts_with($message, 'Unknown ticker')) {
                return response()->json(['message' => "No tracked stock found for {$ticker}."], 404);
            }

            return response()->json(['message' => $message], 503);
        }

        $priceHistory = $this->recentPrices($ticker);

        if (! ($result['eligible'] ?? false)) {
            return response()->json([
                'success' => true,
                'growth' => [
                    'ticker' => $result['ticker'],
                    'name' => $result['name'],
                    'eligible' => false,
                    'reason' => $result['reason'] ?? 'Not eligible for scoring.',
                ],
                'evaluation' => $result + ['price_history' => $priceHistory],
            ]);
        }

        $item = $result['item'];
        $price = (float) $item['price'];
        $raw = (float) ($item['raw_score'] ?? $item['raw'] ?? 0.0);
        $growthFactor = exp($raw);
        $growthPct = ($growthFactor - 1.0) * 100.0;
        $targetPrice = $price * $growthFactor;
        $horizonDays = (int) config('listikhar.model.horizon_days', 20);

        $growth = [
            'ticker' => $result['ticker'],
            'name' => $result['name'],
            'sector' => $result['sector'],
            'exchange' => $result['exchange'],
            'market_cap' => $result['market_cap'],
            'date' => $result['date'],
            'model_version' => $result['model_version'],
            'eligible' => true,
            'rank' => $result['rank'],
            'percentile' => $result['percentile'],
            'peers' => $result['peers'],
            'price' => round($price, 4),
            'score' => $item['score'],
            'raw_score' => round($raw, 6),
            'potential_growth_pct' => round($growthPct, 2),
            'expected_growth_pct' => round($growthPct, 2),
            'growth_pct' => round($growthPct, 2),
            'growth' => round($growthPct, 2),
            'potential_growth' => round($growthPct, 2),
            'target_price' => round($targetPrice, 4),
            'target_pct' => round($growthPct, 2),
            'horizon_days' => $horizonDays,
            'rationale' => $item['rationale'],
            'key_features' => $item['key_features'],
            'risks' => $item['risks'] ?? [],
            'indicators' => $item['features'] ?? $item['key_features'] ?? [],
            'features' => $item['features'] ?? [],
        ];

        return response()->json(array_merge([
            'success' => true,
            'evaluation' => $result + ['price_history' => $priceHistory],
            'price_history' => $priceHistory,
        ], $growth, [
            'growth' => $growth,
            'growth_potential' => $growth,
            'potential_growth' => $growth,
        ]));
    }

    /**
     * @return list<array{date: string, close: float, volume: int}>
     */
    private function recentPrices(string $ticker): array
    {
        $stock = Stock::query()->where('ticker', $ticker)->first();

        if (! $stock instanceof Stock) {
            return [];
        }

        return DB::table('stock_prices')
            ->where('stock_id', $stock->id)
            ->orderByDesc('trade_date')
            ->limit(30)
            ->get(['trade_date', 'close', 'volume'])
            ->map(fn ($row): array => [
                'date' => $row->trade_date,
                'close' => (float) $row->close,
                'volume' => (int) $row->volume,
            ])
            ->reverse()
            ->values()
            ->all();
    }
}
