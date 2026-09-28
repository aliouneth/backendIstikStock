<?php

namespace App\Console\Commands;

use App\Services\Data\SyntheticHistoricalDataGenerator;
use Illuminate\Console\Command;

class SeedHistoricalData extends Command
{
    protected $signature = 'listikhar:seed-historical
        {--force : Wipe and regenerate the dataset}';

    protected $description = 'Generate the historical dataset: last N top-performing penny stocks + macro environment';

    public function handle(SyntheticHistoricalDataGenerator $generator): int
    {
        $this->info('Generating historical penny-stock dataset (this may take a minute)...');

        $stats = $generator->generate($this->option('force'));

        if (isset($stats['skipped'])) {
            $this->info('Skipped: '.$stats['reason']);

            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            collect($stats)
                ->map(fn ($v, $k) => [$k, is_array($v) ? implode(' → ', $v) : (string) $v])
                ->all()
        );
        $this->info('Done.');

        return self::SUCCESS;
    }
}
