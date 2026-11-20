<?php

namespace App\Console\Commands;

use App\Services\Ml\ModelRepository;
use App\Services\Ml\ModelTrainer;
use Illuminate\Console\Command;

class TrainModel extends Command
{
    protected $signature = 'listikhar:train
        {--force : Retrain even if the model is fresh}';

    protected $description = 'Train (or retrain) the penny-stock ranking model from stored historical data';

    public function handle(ModelTrainer $trainer, ModelRepository $repo): int
    {
        if ($repo->exists() && ! $this->option('force')) {
            $meta = $repo->meta();
            $this->info('Existing model: '.($meta['version'] ?? '?').' trained '.($meta['trained_at'] ?? '?'));
            if (! $repo->isStale()) {
                $this->info('Model is fresh (use --force to retrain anyway).');

                return self::SUCCESS;
            }
        }

        $this->info('Building next-session runner dataset + training the ranking model...');

        $bar = $this->output->createProgressBar((int) config('listikhar.model.n_estimators', 120));
        $bar->setFormat(' %current%/%max% trees %bar% %percent:3s%%');
        $bar->start();

        $meta = $trainer->train(function (int $done, int $total) use ($bar) {
            $bar->setMaxSteps($total);
            $bar->setProgress($done);
        });

        $bar->finish();
        $this->newLine(2);

        $holdout = $meta['metrics']['holdout'] ?? [];
        $ranking = $meta['metrics']['holdout_ranking'] ?? [];
        $rankers = $ranking['rankers'] ?? [];
        $this->table(
            ['Metric', 'Value'],
            [
                ['version', $meta['version']],
                ['trained_at', $meta['trained_at']],
                ['train samples', (string) ($meta['dataset']['train_samples'] ?? '?')],
                ['test samples', (string) ($meta['dataset']['test_samples'] ?? '?')],
                ['holdout RMSE', (string) ($holdout['rmse'] ?? '?')],
                ['holdout MAE', (string) ($holdout['mae'] ?? '?')],
                ['holdout R²', (string) ($holdout['r2'] ?? '?')],
                ['holdout Spearman (rank corr.)', (string) ($holdout['spearman'] ?? '?')],
                ['daily top-5 target lift', (string) ($ranking['avg_top5_lift'] ?? '?')],
                ['daily top-5 runner rate', (string) ($ranking['top5_runner_rate_pct'] ?? '?').'%'],
                ['daily top-5 adverse rate', (string) ($ranking['top5_adverse_rate_pct'] ?? '?').'%'],
                ['top-5 positive-score coverage', (string) ($rankers['model']['top5_positive_score_rate_pct'] ?? '?').'%'],
                ['model picks per holdout date', (string) ($rankers['model']['avg_selected_picks_per_day'] ?? '?')],
                ['sampled-universe runner rate', (string) ($ranking['cross_section_runner_rate_pct'] ?? '?').'%'],
                ['relative-volume top-5 runner rate', (string) ($rankers['relative_volume']['top5_runner_rate_pct'] ?? '?').'%'],
                ['gap-volume-rule top-5 runner rate', (string) ($rankers['gap_volume_rule']['top5_runner_rate_pct'] ?? '?').'%'],
                ['holdout dates ranked', (string) ($ranking['dates'] ?? '?')],
                ['fit seconds', (string) ($meta['fit']['fit_seconds'] ?? '?')],
            ]
        );

        return self::SUCCESS;
    }
}
