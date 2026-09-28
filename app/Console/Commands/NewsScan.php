<?php

namespace App\Console\Commands;

use App\Services\News\NewsSurgeScanner;
use Illuminate\Console\Command;

class NewsScan extends Command
{
    protected $signature = 'listikhar:news-scan
        {--top=0 : Number of results (default: config)}';

    protected $description = 'Scan fresh news for top model candidates and rank stocks likely to jump within days';

    public function handle(NewsSurgeScanner $scanner): int
    {
        $this->info('Scanning fresh news across top model candidates...');

        $payload = $scanner->scan((int) $this->option('top'));

        $this->line(sprintf(
            '  candidates=%d with_fresh_news=%d took=%.1fs',
            $payload['candidates_scanned'],
            $payload['with_fresh_news'],
            $payload['took_seconds']
        ));
        $this->newLine();

        if ($payload['items'] === []) {
            $this->warn('No candidates had fresh headlines in the lookback window.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Ticker', 'Surge', 'Model', 'News', 'Price', 'Target', '+%', 'Sector'],
            array_map(fn ($item) => [
                $item['rank'],
                $item['ticker'],
                $item['surge_score'],
                $item['model_score'],
                $item['news_score'],
                '$'.$item['price'],
                '$'.$item['target_price'],
                '+'.number_format($item['target_pct'], 1).'%',
                $item['sector'] ?? '—',
            ], $payload['items'])
        );

        foreach ($payload['items'] as $item) {
            $this->line('  <fg=cyan>'.$item['ticker'].'</> — '.$item['reasoning']);
        }

        return self::SUCCESS;
    }
}
