<?php

namespace Database\Seeders;

use App\Services\Data\SyntheticHistoricalDataGenerator;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SubscriptionSeeder::class);

        $generator = app(SyntheticHistoricalDataGenerator::class);

        $this->command?->info('Seeding historical penny-stock dataset (1000 top performers + macro)...');

        $stats = $generator->generate();

        if (isset($stats['skipped'])) {
            $this->command?->info('Dataset already present — skipped.');
        } else {
            $this->command?->info(sprintf(
                'Seeded %d stocks, %d price rows, %d events, %d macro days.',
                $stats['stocks'],
                $stats['price_rows'],
                $stats['events'],
                $stats['trading_days']
            ));
        }
    }
}
