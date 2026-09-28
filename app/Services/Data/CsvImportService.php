<?php

namespace App\Services\Data;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Imports real OHLCV history from CSV files — the plug-in point for vendor
 * datasets (Polygon, Tiingo, Alpha Vantage exports, Stooq, ...).
 *
 * Expected columns (header, case-insensitive):
 *   date, open, high, low, close, volume
 * The filename (without extension) is used as the ticker symbol when
 * importing a directory.
 */
class CsvImportService
{
    /** @return array<string, mixed> */
    public function importFile(string $path, string $ticker, array $stockMeta = []): array
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("Cannot read CSV: {$path}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \InvalidArgumentException("Empty CSV: {$path}");
        }

        $map = [];
        foreach (array_values($header) as $i => $col) {
            $map[strtolower(trim((string) $col))] = $i;
        }
        foreach (['date', 'open', 'high', 'low', 'close', 'volume'] as $required) {
            if (! isset($map[$required])) {
                fclose($handle);
                throw new \InvalidArgumentException("CSV {$path} is missing column '{$required}'");
            }
        }

        $stock = Stock::firstOrNew(['ticker' => strtoupper($ticker)]);
        $stock->fill(array_merge([
            'name' => $stockMeta['name'] ?? strtoupper($ticker),
            'sector' => $stockMeta['sector'] ?? 'Unclassified',
            'industry' => $stockMeta['industry'] ?? null,
            'source' => 'csv',
            'is_penny' => true,
        ], array_intersect_key($stockMeta, array_flip(['name', 'sector', 'industry', 'exchange', 'market_cap', 'float_shares']))));
        $stock->save();

        $now = now();
        $rows = [];
        $imported = 0;
        $lastClose = null;
        $volumes = [];

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < 6) {
                continue;
            }
            $date = trim((string) $line[$map['date']]);
            if ($date === '' || strtolower($date) === 'date') {
                continue;
            }
            $date = substr(str_replace('/', '-', $date), 0, 10);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $parsed = strtotime($date);
                if ($parsed === false) {
                    continue;
                }
                $date = date('Y-m-d', $parsed);
            }

            $close = (float) $line[$map['close']];
            if ($close <= 0) {
                continue;
            }

            $volume = max(0, (int) $line[$map['volume']]);
            $rows[] = [
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'open' => round((float) ($line[$map['open']] ?: $close), 4),
                'high' => round((float) ($line[$map['high']] ?: $close), 4),
                'low' => round((float) ($line[$map['low']] ?: $close), 4),
                'close' => round($close, 4),
                'volume' => $volume,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $lastClose = $close;
            $volumes[] = $volume;
            $imported++;

            if (count($rows) >= 1000) {
                DB::table('stock_prices')->insertOrIgnore($rows);
                $rows = [];
            }
        }
        fclose($handle);

        if ($rows !== []) {
            DB::table('stock_prices')->insertOrIgnore($rows);
        }

        $last20 = array_slice($volumes, -20);
        $stock->forceFill([
            'last_price' => $lastClose,
            'avg_volume' => $last20 !== []
                ? (int) round(array_sum($last20) / count($last20))
                : $stock->avg_volume,
        ])->save();

        return [
            'ticker' => $stock->ticker,
            'rows_imported' => $imported,
            'last_price' => $lastClose,
        ];
    }

    /**
     * Import every *.csv in a directory (ticker = filename).
     *
     * @return list<array<string, mixed>>
     */
    public function importDirectory(string $directory): array
    {
        $results = [];
        foreach (glob(rtrim($directory, '/\\').'/*.csv') ?: [] as $file) {
            $ticker = strtoupper(pathinfo($file, PATHINFO_FILENAME));
            $results[] = $this->importFile($file, $ticker);
        }

        return $results;
    }
}
