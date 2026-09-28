<?php

namespace App\Console\Commands;

use App\Services\Pipeline\DailyAnalysisService;
use Illuminate\Console\Command;

class BackfillRecommendations extends Command
{
    protected $signature = 'listikhar:backfill
        {--days=15 : Number of past trading days to backfill}';

    protected $description = 'Generate recommendations for past trading days (history view + feedback dataset)';

    public function handle(DailyAnalysisService $analysis): int
    {
        $days = max(1, (int) $this->option('days'));
        $this->info("Backfilling recommendations for the last {$days} trading days...");

        try {
            $result = $analysis->backfill($days);
        } catch (\Throwable $e) {
            $this->error('Backfill failed: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['dates'] as $entry) {
            $this->line(sprintf('  %s → %s', $entry['date'], implode(', ', $entry['picks'])));
        }

        $this->info(sprintf(
            'Backfilled %d dates. Tracking: %d rows inserted.',
            $result['backfilled_dates'],
            $result['tracking']['inserted']
        ));

        return self::SUCCESS;
    }
}
