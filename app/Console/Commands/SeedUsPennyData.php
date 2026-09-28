<?php

namespace App\Console\Commands;

use App\Services\Data\MacroDataService;
use App\Services\Data\UsPennyUniverseService;
use Illuminate\Console\Command;

class SeedUsPennyData extends Command
{
    protected $signature = 'listikhar:seed-us-penny
        {--append : Keep existing data and add only missing symbols}
        {--limit=0 : Max symbols to sync (0 = all candidates)}
        {--range=3y : Yahoo history range per symbol}';

    protected $description = 'Build the real US penny-stock universe (Nasdaq/NYSE/NYSE American) with Yahoo history + real macro data';

    public function handle(UsPennyUniverseService $universe, MacroDataService $macro): int
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $append = (bool) $this->option('append');
        $limit = (int) $this->option('limit');
        $range = (string) $this->option('range');

        if (! $append) {
            $this->warn('Wiping existing market/model data (stocks, prices, macro, recommendations, runs)...');
            $universe->wipeDomain();
        }

        $this->info('Rebuilding macro environment (indices, Fed funds futures, BLS CPI)...');
        $macroSummary = $macro->rebuild();
        if (isset($macroSummary['error'])) {
            $this->error('Macro rebuild failed: '.$macroSummary['error']);

            return self::FAILURE;
        }
        $this->line('  '.json_encode($macroSummary));

        $this->info('Fetching US listings from the Nasdaq screener...');
        $result = $universe->candidates();
        $candidates = $result['candidates'];
        if ($limit > 0) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $this->line(sprintf(
            '  listed=%d penny candidates=%d rejected(non-penny/excluded)=%d',
            $result['listed'],
            count($candidates),
            $result['rejected']
        ));

        if ($candidates === []) {
            $this->error('No candidates found — screener unreachable?');

            return self::FAILURE;
        }

        $this->info(sprintf('Syncing %d symbols from Yahoo (%s history)...', count($candidates), $range));
        $bar = $this->output->createProgressBar(count($candidates));
        $bar->start();

        $stats = $universe->sync($candidates, $range, $append, fn (int $done) => $bar->setProgress($done));

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Value'],
            collect($stats)
                ->map(fn ($v, $k) => [$k, is_array($v)
                    ? ($v === [] ? 'none' : implode(', ', array_slice($v, 0, 15)).(count($v) > 15 ? '…' : ''))
                    : (string) $v])
                ->all()
        );

        if ($stats['synced'] === 0 && $stats['already_present'] === 0) {
            $this->error('No symbols synced — Yahoo unreachable?');

            return self::FAILURE;
        }

        $this->info('Done. Next: php artisan listikhar:train --force');

        return self::SUCCESS;
    }
}
