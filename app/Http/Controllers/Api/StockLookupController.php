<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Services\Ml\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockLookupController extends Controller
{
    public function __construct(private readonly ScoringService $scoring) {}

    /**
     * Type-ahead search across the tracked penny-stock universe.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 1) {
            return response()->json(['results' => []]);
        }

        $like = '%'.mb_strtolower($q).'%';

        $results = Stock::query()
            ->where(function ($builder) use ($like) {
                $builder->where('ticker', 'like', $like)
                    ->orWhere('name', 'like', $like);
            })
            ->orderByRaw('CASE WHEN ticker = ? THEN 0 ELSE 1 END', [mb_strtoupper($q)])
            ->orderBy('ticker')
            ->limit(20)
            ->get(['id', 'ticker', 'name', 'sector', 'exchange', 'last_price', 'is_penny'])
            ->map(fn (Stock $stock): array => [
                'ticker' => $stock->ticker,
                'name' => $stock->name,
                'sector' => $stock->sector ?: 'Unclassified',
                'exchange' => $stock->exchange,
                'last_price' => $stock->last_price === null ? null : (float) $stock->last_price,
            ])
            ->all();

        return response()->json(['results' => $results]);
    }

    /**
     * Score one ticker against the model and place it within the ranked
     * universe, so the number is comparable to the "Today's picks" list.
     */
    public function evaluate(Request $request, string $ticker): JsonResponse
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

        return response()->json([
            'evaluation' => $result + ['price_history' => $this->recentPrices($ticker)],
        ]);
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
