<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PerformanceTrackingService;
use Illuminate\Console\Command;

class TrackPerformance extends Command
{
    protected $signature = 'listikhar:track-performance';

    protected $description = 'Update stock_performance_tracking for all outstanding recommendations';

    public function handle(PerformanceTrackingService $tracker): int
    {
        $stats = $tracker->update();

        $this->info(sprintf(
            'Recommendations: %d | tracking rows inserted: %d | updated: %d',
            $stats['recommendations'],
            $stats['inserted'],
            $stats['updated']
        ));

        return self::SUCCESS;
    }
}
