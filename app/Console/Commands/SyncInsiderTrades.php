<?php

namespace App\Console\Commands;

use App\Services\Data\InsiderTradeService;
use Illuminate\Console\Command;

class SyncInsiderTrades extends Command
{
    protected $signature = 'listikhar:sync-insider-trades
        {--tickers= : Comma-separated tickers (default: every stored stock)}
        {--since=2020-01-01 : Only retain insider transactions on or after this date}
        {--max-pages= : Maximum Nasdaq transaction pages per ticker}';

    protected $description = 'Sync online insider transactions into corporate events';

    public function handle(InsiderTradeService $insider): int
    {
        $tickers = $this->option('tickers') !== null
            ? array_filter(array_map('trim', explode(',', (string) $this->option('tickers'))))
            : null;
        $maxPages = $this->option('max-pages') !== null ? max(1, (int) $this->option('max-pages')) : null;

        $this->info('Syncing insider transactions...');

        $stats = $insider->sync(
            $tickers,
            (string) $this->option('since'),
            $maxPages,
            function (int $done, int $total): void {
                $this->output->write("\r{$done}/{$total} tickers");
            }
        );
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['attempted', (string) $stats['attempted']],
                ['synced', (string) $stats['synced']],
                ['events stored', (string) $stats['events']],
                ['buy events', (string) $stats['buy_events']],
                ['sell events', (string) $stats['sell_events']],
                ['failed', $stats['failed'] === [] ? 'none' : implode(', ', $stats['failed'])],
            ]
        );

        return self::SUCCESS;
    }
}
