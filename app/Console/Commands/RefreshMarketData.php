<?php

namespace App\Console\Commands;

use App\Services\Data\MacroDataService;
use App\Services\Data\MarketDataRefreshService;
use Illuminate\Console\Command;

class RefreshMarketData extends Command
{
    protected $signature = 'listikhar:refresh-data
        {--macro-only : Only refresh the macro environment}
        {--tickers= : Comma-separated symbols (default: whole stored universe)}
        {--force : Refresh even if stored bars look current}';

    protected $description = 'Refresh latest prices for the tradable universe and macro indicators from live sources';

    public function handle(
        MarketDataRefreshService $market,
        MacroDataService $macro,
    ): int {
        if (! $this->option('macro-only')) {
            $tickers = $this->option('tickers');
            $tickers = $tickers === null || $tickers === ''
                ? null
                : array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $tickers))));

            $this->info('Refreshing market data (Yahoo Finance)...');
            $marketSummary = $market->refresh($tickers, ! (bool) $this->option('force'));
            $this->line(sprintf(
                '  attempted=%d updated=%d failed=%s%s',
                $marketSummary['attempted'],
                $marketSummary['updated'],
                isset($marketSummary['failed']) && $marketSummary['failed'] !== [] ? implode(',', $marketSummary['failed']) : 'none',
                isset($marketSummary['note']) ? ' ('.$marketSummary['note'].')' : ''
            ));
        }

        $this->info('Refreshing macro environment...');
        $macroSummary = $macro->refresh();
        $this->line('  '.json_encode($macroSummary));

        $this->info('Refresh complete.');

        return self::SUCCESS;
    }
}
