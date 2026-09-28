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

        $this->info('Building dataset + training histogram gradient boosting model...');

        $bar = $this->output->createProgressBar(100);
        $bar->setFormat(' %current%/%max% trees %bar% %percent:3s%%');
        $bar->start();

        $meta = $trainer->train(function (int $done, int $total) use ($bar) {
            $bar->setMaxSteps($total);
            $bar->setProgress($done);
        });

        $bar->finish();
        $this->newLine(2);

        $holdout = $meta['metrics']['holdout'] ?? [];
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
                ['fit seconds', (string) ($meta['fit']['fit_seconds'] ?? '?')],
            ]
        );

        return self::SUCCESS;
    }
}
