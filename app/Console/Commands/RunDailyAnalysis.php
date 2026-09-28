<?php

namespace App\Console\Commands;

use App\Services\Pipeline\DailyAnalysisService;
use Illuminate\Console\Command;

class RunDailyAnalysis extends Command
{
    protected $signature = 'listikhar:daily-analysis
        {--no-refresh : Skip live data refresh}
        {--backfill= : Also backfill recommendations for the last N trading days}';

    protected $description = 'Run the full daily pipeline: refresh → infer → store top 5 recommendations';

    public function handle(DailyAnalysisService $analysis): int
    {
        $refresh = ! $this->option('no-refresh');

        $this->info('Running daily analysis'.($refresh ? '' : ' (no live refresh)').'...');

        try {
            $payload = $analysis->run($refresh);
        } catch (\Throwable $e) {
            $this->error('Analysis failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Date: %s | model %s | candidates %d | took %.1fs',
            $payload['date'],
            $payload['model_version'],
            $payload['scored'],
            $payload['took_seconds']
        ));

        $rows = [];
        foreach ($payload['recommendations'] as $rec) {
            $rows[] = [
                $rec['rank'],
                $rec['ticker'],
                $rec['sector'],
                number_format($rec['price'], 3),
                number_format($rec['score'], 1),
                mb_substr($rec['rationale'], 0, 70).'…',
            ];
        }
        $this->table(['#', 'Ticker', 'Sector', 'Price', 'Score', 'Rationale'], $rows);

        if ($this->option('backfill') !== null) {
            $days = max(1, (int) $this->option('backfill'));
            $this->info("Backfilling {$days} trading days of recommendations...");
            $backfill = $analysis->backfill($days);
            $this->info('Backfilled dates: '.$backfill['backfilled_dates']);
        }

        return self::SUCCESS;
    }
}
