<?php

namespace App\Console\Commands;

use App\Services\Data\CsvImportService;
use Illuminate\Console\Command;

class ImportCsv extends Command
{
    protected $signature = 'listikhar:import-csv
        {path : Path to a CSV file or a directory of CSVs (ticker = filename)}
        {--sector=Unclassified}
        {--name=}
        {--exchange=}';

    protected $description = 'Import historical OHLCV penny-stock data from CSV (columns: date,open,high,low,close,volume)';

    public function handle(CsvImportService $importer): int
    {
        $path = (string) $this->argument('path');
        $meta = array_filter([
            'sector' => $this->option('sector'),
            'name' => $this->option('name'),
            'exchange' => $this->option('exchange'),
        ], fn ($v) => $v !== null && $v !== '');

        try {
            if (is_dir($path)) {
                $results = $importer->importDirectory($path);
                if ($results === []) {
                    $this->warn('No CSV files found in directory.');

                    return self::FAILURE;
                }
                foreach ($results as $r) {
                    $this->line(sprintf('  %s: %d rows (last %s)', $r['ticker'], $r['rows_imported'], $r['last_price']));
                }
                $this->info('Imported '.count($results).' symbols.');
            } else {
                $ticker = strtoupper(pathinfo($path, PATHINFO_FILENAME));
                $result = $importer->importFile($path, $ticker, $meta);
                $this->info(sprintf(
                    '%s: %d rows imported (last close %s)',
                    $result['ticker'],
                    $result['rows_imported'],
                    $result['last_price']
                ));
            }
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
