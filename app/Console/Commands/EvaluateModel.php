<?php

namespace App\Console\Commands;

use App\Services\Pipeline\RecommendationReport;
use Illuminate\Console\Command;

class EvaluateModel extends Command
{
    protected $signature = 'listikhar:evaluate';

    protected $description = 'Evaluate recommendation performance vs benchmark and show model metrics';

    public function handle(RecommendationReport $report): int
    {
        $model = $report->modelInfo();

        if ($model['trained']) {
            $holdout = $model['metrics']['holdout'] ?? [];
            $this->info('Model '.$model['version'].' trained '.$model['trained_at']);
            $this->table(
                ['Holdout metric', 'Value'],
                collect($holdout)->map(fn ($v, $k) => [$k, (string) $v])->all()
            );
        } else {
            $this->warn('No model trained yet.');
        }

        $evaluation = $report->evaluate();
        $this->newLine();
        $this->info('Backtest of stored recommendations vs Russell 2000:');
        $this->table(
            ['Metric', 'Value'],
            collect($evaluation)->map(fn ($v, $k) => [$k, is_scalar($v) ? (string) $v : json_encode($v)])->all()
        );

        return self::SUCCESS;
    }
}
