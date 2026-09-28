<?php

namespace App\Services\Data;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the macro_environment table:
 *  - S&P 500 / Nasdaq / Russell 2000 / VIX closes from Yahoo Finance (^GSPC,
 *    ^IXIC, ^RUT, ^VIX) — no API key required.
 *  - Fed Funds rate + CPI (YoY) from FRED when LISTIKHAR_FRED_API_KEY is set,
 *    otherwise the stored series is kept (seeded dataset).
 */
class MacroDataService
{
    public function __construct(private readonly YahooFinanceClient $yahoo) {}

    /**
     * Full rebuild of the macro environment from real sources:
     *  - S&P 500 / Nasdaq / Russell 2000 / VIX closes (Yahoo),
     *  - implied Fed Funds rate from 30-day Fed Funds futures (ZQ=F),
     *  - CPI year-over-year from the US BLS (no API key required).
     * The macro table is expected to be empty (fresh rebuild).
     *
     * @return array<string, mixed>
     */
    public function rebuild(): array
    {
        set_time_limit(0);

        DB::table('macro_environment')->delete();

        $seriesMap = [
            '^GSPC' => 'sp500_close',
            '^IXIC' => 'nasdaq_close',
            '^RUT' => 'russell2000_close',
            '^VIX' => 'vix_close',
        ];

        $columns = ['sp500_close' => [], 'nasdaq_close' => [], 'russell2000_close' => [], 'vix_close' => []];
        $fetched = 0;

        foreach ($seriesMap as $symbol => $column) {
            $chart = $this->yahoo->chart($symbol, '5y', '1d');
            usleep(250_000);
            if ($chart === null || $chart['bars'] === []) {
                continue;
            }
            $fetched++;
            foreach ($chart['bars'] as $bar) {
                $columns[$column][$bar['date']] = $bar['close'];
            }
        }

        $calendar = array_keys($columns['sp500_close']);
        sort($calendar);
        if ($calendar === []) {
            return ['error' => 'no index data available'];
        }

        // Implied Fed Funds rate: 30-day fed funds futures price → 100 − price.
        $fedByDate = [];
        $zq = $this->yahoo->chart('ZQ=F', '5y', '1d');
        usleep(250_000);
        foreach ($zq['bars'] ?? [] as $bar) {
            $rate = round(100 - (float) $bar['close'], 3);
            if ($rate > 0 && $rate < 25) {
                $fedByDate[$bar['date']] = $rate;
            }
        }

        // CPI YoY (monthly, stamped on the 1st of each month).
        $cpiStamps = $this->blsCpiYoy();
        ksort($cpiStamps);
        $cpiList = [];
        foreach ($cpiStamps as $date => $value) {
            $cpiList[] = ['date' => $date, 'value' => $value];
        }

        $rows = [];
        $lastFed = null;
        $lastCpi = null;
        $cpiIdx = 0;

        foreach ($calendar as $date) {
            if (isset($fedByDate[$date])) {
                $lastFed = $fedByDate[$date];
            }
            while ($cpiIdx < count($cpiList) && $cpiList[$cpiIdx]['date'] <= $date) {
                $lastCpi = $cpiList[$cpiIdx]['value'];
                $cpiIdx++;
            }

            $rows[] = [
                'observation_date' => $date,
                'fed_funds_rate' => $lastFed,
                'cpi_yoy' => $lastCpi,
                'sp500_close' => $columns['sp500_close'][$date] ?? null,
                'nasdaq_close' => $columns['nasdaq_close'][$date] ?? null,
                'russell2000_close' => $columns['russell2000_close'][$date] ?? null,
                'vix_close' => $columns['vix_close'][$date] ?? null,
                'sector_sentiment' => null,
                'source' => 'yahoo+bls',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('macro_environment')->insert($chunk);
        }

        $summary = [
            'index_series_fetched' => $fetched,
            'rows' => count($rows),
            'fed_filled' => count($fedByDate),
            'cpi_months' => count($cpiStamps),
        ];

        Log::info('Macro environment rebuilt from real sources', $summary);

        return $summary;
    }

    /**
     * CPI year-over-year from the US Bureau of Labor Statistics (keyless).
     *
     * @return array<string, float> date (1st of month) => YoY percent
     */
    private function blsCpiYoy(): array
    {
        $startYear = (int) date('Y') - 4;

        try {
            $client = new Client([
                'timeout' => 30,
                'verify' => config('listikhar.yahoo.ca_bundle') ?? true,
            ]);
            $response = $client->get(
                "https://api.bls.gov/publicAPI/v2/timeseries/data/CUUR0000SA0?startyear={$startYear}&endyear=".date('Y'),
                ['headers' => ['User-Agent' => (string) config('listikhar.yahoo.user_agent')]]
            );
            $payload = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            Log::warning('BLS CPI fetch failed', ['error' => $e->getMessage()]);

            return [];
        }

        $levels = [];
        foreach ($payload['Results']['series'][0]['data'] ?? [] as $row) {
            $period = (string) ($row['period'] ?? '');
            if (! preg_match('/^M(0[1-9]|1[0-2])$/', $period)) {
                continue;
            }
            $value = (float) ($row['value'] ?? 0);
            if ($value > 0) {
                $levels[sprintf('%s-%s', $row['year'], substr($period, 1))] = $value;
            }
        }
        ksort($levels);

        $yoy = [];
        foreach ($levels as $ym => $value) {
            [$year, $month] = explode('-', $ym);
            $prevYm = sprintf('%04d-%02d', (int) $year - 1, (int) $month);
            if (isset($levels[$prevYm]) && $levels[$prevYm] > 0) {
                $yoy[$ym.'-01'] = round((($value / $levels[$prevYm]) - 1) * 100, 3);
            }
        }

        return $yoy;
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $seriesMap = [
            '^GSPC' => 'sp500_close',
            '^IXIC' => 'nasdaq_close',
            '^RUT' => 'russell2000_close',
            '^VIX' => 'vix_close',
        ];

        $columns = ['sp500_close' => null, 'nasdaq_close' => null, 'russell2000_close' => null, 'vix_close' => null];
        $fetched = 0;

        foreach ($seriesMap as $symbol => $column) {
            $chart = $this->yahoo->chart($symbol, '5y', '1d');
            if ($chart === null || $chart['bars'] === []) {
                continue;
            }
            $fetched++;
            foreach ($chart['bars'] as $bar) {
                $columns[$column][$bar['date']] = $bar['close'];
            }
        }

        $rowsByDate = [];
        foreach (DB::table('macro_environment')->orderBy('observation_date')->get() as $row) {
            $rowsByDate[$row->observation_date] = $row;
        }

        $existing = array_keys($rowsByDate);
        $maxExisting = $existing === [] ? null : max($existing);

        // 1) Update existing rows where Yahoo provided the date.
        $updates = 0;
        foreach ($rowsByDate as $date => $row) {
            $values = [];
            foreach ($columns as $column => $byDate) {
                if (isset($byDate[$date])) {
                    $values[$column] = $byDate[$date];
                }
            }
            if ($values !== []) {
                DB::table('macro_environment')->where('id', $row->id)->update($values);
                $updates++;
            }
        }

        // 2) Append brand-new trading days beyond the stored window.
        $inserted = 0;
        foreach ($columns['sp500_close'] ?? [] as $date => $close) {
            if ($maxExisting !== null && $date <= $maxExisting) {
                continue;
            }
            $lastFed = $maxExisting !== null ? $this->latestValue($rowsByDate, 'fed_funds_rate') : null;
            $lastCpi = $maxExisting !== null ? $this->latestValue($rowsByDate, 'cpi_yoy') : null;

            DB::table('macro_environment')->insert([
                'observation_date' => $date,
                'fed_funds_rate' => $lastFed,
                'cpi_yoy' => $lastCpi,
                'sp500_close' => $columns['sp500_close'][$date] ?? null,
                'nasdaq_close' => $columns['nasdaq_close'][$date] ?? null,
                'russell2000_close' => $columns['russell2000_close'][$date] ?? null,
                'vix_close' => $columns['vix_close'][$date] ?? null,
                'sector_sentiment' => null,
                'source' => 'yahoo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        $fred = $this->refreshFred();

        $summary = [
            'index_series_fetched' => $fetched,
            'rows_updated' => $updates,
            'rows_inserted' => $inserted,
            'fred' => $fred,
        ];

        Log::info('Macro environment refreshed', $summary);

        return $summary;
    }

    /** @param  array<string, \stdClass>  $rowsByDate */
    private function latestValue(array $rowsByDate, string $column): ?float
    {
        foreach (array_reverse($rowsByDate, true) as $row) {
            if ($row->{$column} !== null) {
                return (float) $row->{$column};
            }
        }

        return null;
    }

    /**
     * Optional: real Fed Funds (FEDFUNDS) and CPI YoY (CPIAUCSL) from FRED.
     *
     * @return array<string, mixed>
     */
    private function refreshFred(): array
    {
        $key = config('listikhar.fred_api_key');
        if (empty($key)) {
            return ['enabled' => false, 'reason' => 'no FRED API key — stored series kept'];
        }

        $fed = $this->fredSeries('FEDFUNDS', $key);
        $cpi = $this->fredSeries('CPIAUCSL', $key);

        $cpiYoY = [];
        $byDate = [];
        foreach ($cpi as $row) {
            $byDate[$row['date']] = (float) $row['value'];
        }
        foreach ($cpi as $row) {
            $lag = date('Y-m-d', strtotime($row['date'].' -1 year'));
            if (isset($byDate[$lag]) && $byDate[$lag] > 0) {
                $cpiYoY[$row['date']] = round((($byDate[$row['date']] / $byDate[$lag]) - 1) * 100, 3);
            }
        }

        $fedUpdates = 0;
        foreach ($fed as $row) {
            $changed = DB::table('macro_environment')
                ->where('observation_date', $row['date'])
                ->update(['fed_funds_rate' => $row['value']]);
            $fedUpdates += $changed;
        }

        // CPI is monthly: stamp each day with the latest monthly YoY value.
        $cpiUpdates = 0;
        ksort($cpiYoY);
        $latest = null;
        $rows = DB::table('macro_environment')->orderBy('observation_date')->get(['id', 'observation_date']);
        foreach ($rows as $row) {
            foreach ($cpiYoY as $date => $value) {
                if ($date <= $row->observation_date) {
                    $latest = $value;
                } else {
                    break;
                }
            }
            if ($latest !== null) {
                $cpiUpdates += DB::table('macro_environment')
                    ->where('id', $row->id)
                    ->update(['cpi_yoy' => $latest]);
            }
        }

        return ['enabled' => true, 'fed_rows' => $fedUpdates, 'cpi_rows' => $cpiUpdates];
    }

    /** @return list<array{date: string, value: float}> */
    private function fredSeries(string $seriesId, string $key): array
    {
        try {
            $client = new Client([
                'timeout' => 15,
                'verify' => config('listikhar.yahoo.ca_bundle') ?? true,
            ]);
            $response = $client->get('https://api.stlouisfed.org/fred/series/observations', [
                'query' => [
                    'series_id' => $seriesId,
                    'api_key' => $key,
                    'file_type' => 'json',
                    'observation_start' => '2020-01-01',
                ],
            ]);
            $payload = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            Log::warning('FRED fetch failed', ['series' => $seriesId, 'error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($payload['observations'] ?? [] as $obs) {
            if (($obs['value'] ?? '.') === '.') {
                continue;
            }
            $out[] = ['date' => $obs['date'], 'value' => (float) $obs['value']];
        }

        return $out;
    }
}
