<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Ml\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WatchlistController extends Controller
{
    public function __construct(private readonly ScoringService $scoring) {}

    /**
     * GET /api/watchlists
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $lists = Watchlist::query()
            ->where('user_id', $user->id)
            ->withCount('items')
            ->orderBy('name')
            ->get(['id', 'user_id', 'name', 'created_at', 'updated_at'])
            ->map(fn (Watchlist $w): array => [
                'id' => $w->id,
                'name' => $w->name,
                'items_count' => (int) $w->items_count,
                'created_at' => $w->created_at?->toIso8601String(),
                'updated_at' => $w->updated_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['watchlists' => $lists]);
    }

    /**
     * POST /api/watchlists
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);
        $user = $request->user();

        if (Watchlist::query()->where('user_id', $user->id)->where('name', $data['name'])->exists()) {
            return response()->json(['message' => 'A list with that name already exists.'], 422);
        }

        $list = Watchlist::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
        ]);

        return response()->json(['watchlist' => [
            'id' => $list->id,
            'name' => $list->name,
            'items_count' => 0,
            'created_at' => $list->created_at?->toIso8601String(),
            'updated_at' => $list->updated_at?->toIso8601String(),
        ]], 201);
    }

    /**
     * PATCH /api/watchlists/{watchlist}
     */
    public function update(Request $request, Watchlist $watchlist): JsonResponse
    {
        $user = $request->user();
        if ($watchlist->user_id !== $user->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        if (Watchlist::query()
            ->where('user_id', $user->id)
            ->where('name', $data['name'])
            ->where('id', '!=', $watchlist->id)
            ->exists()) {
            return response()->json(['message' => 'A list with that name already exists.'], 422);
        }

        $watchlist->fill($data)->save();

        return response()->json(['watchlist' => [
            'id' => $watchlist->id,
            'name' => $watchlist->name,
            'items_count' => $watchlist->items()->count(),
            'created_at' => $watchlist->created_at?->toIso8601String(),
            'updated_at' => $watchlist->updated_at?->toIso8601String(),
        ]]);
    }

    /**
     * DELETE /api/watchlists/{watchlist}
     */
    public function destroy(Request $request, Watchlist $watchlist): JsonResponse
    {
        $user = $request->user();
        if ($watchlist->user_id !== $user->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $watchlist->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * GET /api/watchlists/{watchlist}
     */
    public function show(Request $request, Watchlist $watchlist): JsonResponse
    {
        $user = $request->user();
        if ($watchlist->user_id !== $user->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $items = $watchlist->items()
            ->with('stock')
            ->get(['id', 'watchlist_id', 'stock_id', 'created_at'])
            ->map(fn (WatchlistItem $it): array => [
                'id' => $it->id,
                'ticker' => $it->stock?->ticker,
                'name' => $it->stock?->name,
                'sector' => $it->stock?->sector ?: 'Unclassified',
                'exchange' => $it->stock?->exchange,
                'last_price' => $it->stock?->last_price === null ? null : (float) $it->stock->last_price,
            ])
            ->filter(fn ($x) => $x['ticker'])
            ->values()
            ->all();

        $tickers = array_map(fn ($x) => $x['ticker'], $items);
        $eval = [];
        if ($tickers !== []) {
            try {
                $bulk = $this->scoring->evaluateSymbols($tickers);
                $eval = $bulk['evaluations'];
            } catch (\Throwable $e) {
                $eval = [];
            }
        }

        $withTracks = array_map(function (array $row) use ($eval) {
            $t = strtoupper((string) $row['ticker']);
            $ev = $eval[$t] ?? null;

            return $row + [
                'evaluation' => $ev,
                'price_history' => $this->recentPrices($t),
            ];
        }, $items);

        return response()->json([
            'watchlist' => [
                'id' => $watchlist->id,
                'name' => $watchlist->name,
                'items_count' => count($withTracks),
                'created_at' => $watchlist->created_at?->toIso8601String(),
                'updated_at' => $watchlist->updated_at?->toIso8601String(),
            ],
            'items' => $withTracks,
        ]);
    }

    /**
     * POST /api/watchlists/{watchlist}/items
     */
    public function storeItem(Request $request, Watchlist $watchlist): JsonResponse
    {
        $user = $request->user();
        if ($watchlist->user_id !== $user->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $data = $request->validate([
            'ticker' => ['required', 'string', 'max:12'],
        ]);
        $t = strtoupper(trim($data['ticker']));
        if (! preg_match('/^[A-Z0-9.\-]{1,12}$/', $t)) {
            return response()->json(['message' => 'That does not look like a ticker symbol.'], 422);
        }

        $stock = Stock::query()->where('ticker', $t)->first();
        if (! $stock instanceof Stock) {
            return response()->json(['message' => "No tracked stock found for {$t}."], 404);
        }

        if ($watchlist->items()->where('stock_id', $stock->id)->exists()) {
            return response()->json(['message' => 'Ticker already in this list.'], 422);
        }

        $item = $watchlist->items()->create(['stock_id' => $stock->id]);

        return response()->json(['item' => [
            'id' => $item->id,
            'ticker' => $stock->ticker,
            'name' => $stock->name,
            'sector' => $stock->sector ?: 'Unclassified',
            'exchange' => $stock->exchange,
            'last_price' => $stock->last_price === null ? null : (float) $stock->last_price,
        ]], 201);
    }

    /**
     * DELETE /api/watchlists/{watchlist}/items/{item}
     */
    public function destroyItem(Request $request, Watchlist $watchlist, WatchlistItem $item): JsonResponse
    {
        $user = $request->user();
        if ($watchlist->user_id !== $user->id || $item->watchlist_id !== $watchlist->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $item->delete();

        return response()->json(['message' => 'Removed.']);
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
