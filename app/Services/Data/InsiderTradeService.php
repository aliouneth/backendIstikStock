<?php

namespace App\Services\Data;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs online insider transactions from Nasdaq into corporate events.
 *
 * Each stock/day/direction combination is stored as one aggregate event, so
 * feature engineering can measure trailing insider buying and selling without
 * re-querying Nasdaq during training or scoring.
 */
class InsiderTradeService
{
    private const URL = 'https://api.nasdaq.com/api/company/%s/insider-trades';

    public function __construct(
        private readonly YahooFinanceClient $http,
        private readonly int $pageSize = 100,
        private readonly int $sleepMicroseconds = 250_000,
    ) {}

    /**
     * @param  list<string>|null  $tickers
     * @return array{attempted:int, synced:int, failed:list<string>, events:int, buy_events:int, sell_events:int}
     */
    public function sync(?array $tickers = null, ?string $since = null, ?int $maxPages = null, ?callable $progress = null): array
    {
        $tickers ??= Stock::orderBy('ticker')->pluck('ticker')->all();
        $maxPages ??= 50;
        $stats = [
            'attempted' => count($tickers),
            'synced' => 0,
            'failed' => [],
            'events' => 0,
            'buy_events' => 0,
            'sell_events' => 0,
        ];

        foreach (array_values($tickers) as $index => $ticker) {
            $ticker = strtoupper(trim($ticker));
            try {
                $transactions = $this->transactions($ticker, $since, $maxPages);
                $stored = $this->store($ticker, $transactions);
                $stats['synced']++;
                $stats['events'] += $stored['events'];
                $stats['buy_events'] += $stored['buy_events'];
                $stats['sell_events'] += $stored['sell_events'];
            } catch (Throwable $e) {
                $stats['failed'][] = $ticker;
                Log::warning('Insider trade sync failed', [
                    'ticker' => $ticker,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($progress !== null) {
                $progress($index + 1, count($tickers));
            }
            usleep($this->sleepMicroseconds);
        }

        return $stats;
    }

    /**
     * @return list<array{date:string, direction:string, shares:int, value:float, insider:string, relation:string}>
     */
    private function transactions(string $ticker, ?string $since, int $maxPages): array
    {
        $transactions = [];
        $offset = 0;
        $pages = 0;

        do {
            $payload = $this->fetchPage($ticker, $offset);
            $rows = $payload['data']['transactionTable']['table']['rows'] ?? [];
            if (! is_array($rows) || $rows === []) {
                break;
            }

            $total = (int) ($payload['data']['transactionTable']['totalRecords'] ?? 0);
            foreach ($rows as $row) {
                $transaction = self::parseTransaction(is_array($row) ? $row : []);
                if ($transaction === null) {
                    continue;
                }
                if ($since !== null && $transaction['date'] < $since) {
                    continue;
                }
                $transactions[] = $transaction;
            }

            $pages++;
            $offset += count($rows);
            if ($pages >= $maxPages || ($total > 0 && $offset >= $total)) {
                break;
            }
        } while (true);

        return $transactions;
    }

    /** @return array<string, mixed> */
    private function fetchPage(string $ticker, int $offset): array
    {
        $response = $this->http->rawGet(sprintf(self::URL, rawurlencode($ticker)), [
            'query' => [
                'limit' => $this->pageSize,
                'offset' => $offset,
                'type' => 'all',
                'sortColumn' => 'lastDate',
                'sortOrder' => 'DESC',
            ],
            'headers' => [
                'User-Agent' => (string) config('listikhar.yahoo.user_agent'),
                'Accept' => 'application/json',
            ],
        ]);
        $payload = json_decode((string) $response->getBody(), true);

        if (! is_array($payload)) {
            throw new \RuntimeException("Invalid insider-trade response for [{$ticker}].");
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{date:string, direction:string, shares:int, value:float, insider:string, relation:string}|null
     */
    public static function parseTransaction(array $row): ?array
    {
        $type = strtolower(trim((string) ($row['transactionType'] ?? '')));
        $isBuy = str_contains($type, 'buy');
        $isSell = str_contains($type, 'sell') || str_contains($type, 'disposition');
        if ($isBuy === $isSell) {
            return null;
        }

        $date = self::parseDate((string) ($row['lastDate'] ?? ''));
        if ($date === null) {
            return null;
        }

        $shares = (int) round(self::parseNumber((string) ($row['sharesTraded'] ?? '')));
        if ($shares <= 0) {
            return null;
        }

        $price = self::parseNumber((string) ($row['lastPrice'] ?? ''));

        return [
            'date' => $date,
            'direction' => $isBuy ? 'buy' : 'sell',
            'shares' => $shares,
            'value' => $shares * $price,
            'insider' => trim((string) ($row['insider'] ?? '')),
            'relation' => trim((string) ($row['relation'] ?? '')),
        ];
    }

    private static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['n/j/Y', 'm/d/Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private static function parseNumber(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        return (float) preg_replace('/[^0-9.]/', '', $value);
    }

    /**
     * @param  list<array{date:string, direction:string, shares:int, value:float, insider:string, relation:string}>  $transactions
     * @return array{events:int, buy_events:int, sell_events:int}
     */
    private function store(string $ticker, array $transactions): array
    {
        $stock = Stock::where('ticker', $ticker)->first();
        if (! $stock instanceof Stock) {
            throw new \RuntimeException("Unknown ticker [{$ticker}].");
        }

        $aggregates = [];
        foreach ($transactions as $transaction) {
            $key = $transaction['date'].'|'.$transaction['direction'];
            $aggregates[$key] ??= [
                'date' => $transaction['date'],
                'direction' => $transaction['direction'],
                'shares' => 0,
                'value' => 0.0,
                'count' => 0,
            ];
            $aggregates[$key]['shares'] += $transaction['shares'];
            $aggregates[$key]['value'] += $transaction['value'];
            $aggregates[$key]['count']++;
        }

        return DB::transaction(function () use ($stock, $aggregates) {
            DB::table('corporate_events')
                ->where('stock_id', $stock->id)
                ->whereIn('event_type', ['insider_buy', 'insider_sell'])
                ->delete();

            $rows = [];
            $now = now();
            foreach ($aggregates as $aggregate) {
                $type = $aggregate['direction'] === 'buy' ? 'insider_buy' : 'insider_sell';
                $rows[] = [
                    'stock_id' => $stock->id,
                    'event_date' => $aggregate['date'],
                    'event_type' => $type,
                    'title' => $aggregate['direction'] === 'buy'
                        ? "Insider buys on {$aggregate['date']}"
                        : "Insider sells on {$aggregate['date']}",
                    'description' => "{$aggregate['count']} insider transaction(s), {$aggregate['shares']} shares",
                    'meta' => json_encode([
                        'buy_shares' => $aggregate['direction'] === 'buy' ? $aggregate['shares'] : 0,
                        'buy_value' => $aggregate['direction'] === 'buy' ? round($aggregate['value'], 2) : 0,
                        'sell_shares' => $aggregate['direction'] === 'sell' ? $aggregate['shares'] : 0,
                        'sell_value' => $aggregate['direction'] === 'sell' ? round($aggregate['value'], 2) : 0,
                        'transactions' => $aggregate['count'],
                        'source' => 'nasdaq-insider-trades',
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('corporate_events')->insert($chunk);
            }

            return [
                'events' => count($rows),
                'buy_events' => count(array_filter($rows, fn ($row) => $row['event_type'] === 'insider_buy')),
                'sell_events' => count(array_filter($rows, fn ($row) => $row['event_type'] === 'insider_sell')),
            ];
        });
    }
}
