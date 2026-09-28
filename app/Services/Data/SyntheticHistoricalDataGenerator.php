<?php

namespace App\Services\Data;

use Illuminate\Support\Facades\DB;

/**
 * Generates the historical learning dataset: the last N (default 1000)
 * top-performing penny stocks with daily OHLCV, corporate events, and the
 * macro-economic environment they traded in.
 *
 * The generator is deterministic (seeded RNG) and injects genuine, learnable
 * structure: "explosive run" windows are scheduled preferentially when the
 * macro regime is favorable (Fed cutting, small caps advancing, VIX cooling),
 * so the ML pipeline has real signal to learn ("what preceded strong runs").
 *
 * For real datasets, plug in a vendor through CsvImportService or extend
 * MarketDataRefreshService — the schema is identical.
 */
class SyntheticHistoricalDataGenerator
{
    /** @var list<array{name: string, weight: float, industry: string, ticker_hints: list<string>, news: string}> */
    private array $sectors = [
        ['name' => 'Biotechnology', 'weight' => 0.22, 'industry' => 'Biopharmaceuticals',
            'ticker_hints' => ['BIO', 'GEN', 'THX', 'VIT', 'MRN', 'CRB'],
            'news' => 'Announces positive Phase 2 topline data for lead asset'],
        ['name' => 'Mining & Metals', 'weight' => 0.14, 'industry' => 'Metals & Mining',
            'ticker_hints' => ['AUR', 'IRN', 'SIL', 'COP', 'ZNC', 'LIT'],
            'news' => 'Reports maiden resource estimate at flagship project'],
        ['name' => 'Technology', 'weight' => 0.13, 'industry' => 'Software — Application',
            'ticker_hints' => ['SYS', 'QNT', 'NXT', 'CLD', 'BRD', 'API'],
            'news' => 'Wins multi-year enterprise contract worth $40M'],
        ['name' => 'Energy', 'weight' => 0.12, 'industry' => 'Oil & Gas E&P',
            'ticker_hints' => ['WEL', 'PMP', 'GAS', 'DRL', 'OIL', 'RES'],
            'news' => 'Discovers additional pay zone at Permian asset'],
        ['name' => 'Crypto Mining', 'weight' => 0.08, 'industry' => 'Cryptocurrency Mining',
            'ticker_hints' => ['HSH', 'BLK', 'NET', 'RIG', 'CHP', 'POW'],
            'news' => 'Expands mining capacity with 15,000 new ASIC units'],
        ['name' => 'Cannabis', 'weight' => 0.07, 'industry' => 'Drug Retail — Specialty',
            'ticker_hints' => ['LEF', 'GRN', 'HMP', 'BUD', 'ROA', 'CAN'],
            'news' => 'Enters three new state markets through acquisition'],
        ['name' => 'Electric Vehicles', 'weight' => 0.07, 'industry' => 'Automobile Manufacturers',
            'ticker_hints' => ['VLT', 'ECT', 'MOT', 'CHG', 'RDR', 'TRQ'],
            'news' => 'Receives large fleet order for light-duty EVs'],
        ['name' => 'Telecommunications', 'weight' => 0.06, 'industry' => 'Telecom Services',
            'ticker_hints' => ['SIG', 'WAV', 'CAL', 'FBR', 'MBS', 'TEL'],
            'news' => 'Completes fiber buildout ahead of schedule'],
        ['name' => 'Financial Services', 'weight' => 0.06, 'industry' => 'Credit Services',
            'ticker_hints' => ['CRE', 'CAP', 'FIN', 'LNQ', 'BRG', 'AST'],
            'news' => 'Reports record quarterly origination volume'],
        ['name' => 'Consumer & Retail', 'weight' => 0.05, 'industry' => 'Specialty Retail',
            'ticker_hints' => ['BRT', 'MRT', 'SHO', 'HOM', 'PET', 'TOY'],
            'news' => 'Same-store sales grow 12% year over year'],
    ];

    private array $nameSuffixes = [
        'Therapeutics Inc.', 'Biosciences Corp.', 'Resources Ltd.', 'Holdings Inc.',
        'Systems Inc.', 'Technologies Corp.', 'Energy Inc.', 'Group Inc.',
        'Capital Corp.', 'Industries Inc.', 'Acquisitions Inc.', 'Global Corp.',
    ];

    public function generate(bool $force = false): array
    {
        $cfg = config('listikhar.synthetic');
        $count = (int) $cfg['stocks'];
        $seed = (int) $cfg['seed'];
        $years = (int) $cfg['years'];

        $existing = (int) DB::table('stocks')->where('source', 'historical_dataset')->count();
        if ($existing >= $count && ! $force) {
            return ['skipped' => true, 'reason' => "{$existing} historical stocks already present"];
        }

        if ($force) {
            DB::table('stock_performance_tracking')->delete();
            DB::table('daily_recommendations')->delete();
            DB::table('corporate_events')->delete();
            DB::table('stock_prices')->delete();
            DB::table('stocks')->where('source', 'historical_dataset')->delete();
            DB::table('macro_environment')->delete();
        }

        mt_srand($seed);

        $days = $this->tradingDays($years);
        $n = count($days);

        $macro = $this->buildMacro($days);
        $this->insertMacro($macro['rows']);

        $attractiveness = $this->runAttractiveness($macro);

        $stats = [
            'trading_days' => $n,
            'date_range' => [$days[0], $days[$n - 1]],
            'stocks' => 0,
            'price_rows' => 0,
            'events' => 0,
            'runs' => 0,
        ];

        $usedTickers = [];

        for ($s = 0; $s < $count; $s++) {
            $stockStats = $this->generateStock($s, $days, $macro, $attractiveness, $usedTickers);
            $usedTickers[$stockStats['ticker']] = true;

            $stats['stocks']++;
            $stats['price_rows'] += $stockStats['price_rows'];
            $stats['events'] += $stockStats['events'];
            $stats['runs'] += $stockStats['runs'];
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // Calendar + macro environment
    // ------------------------------------------------------------------

    /** @return list<string> */
    private function tradingDays(int $years): array
    {
        $end = new \DateTimeImmutable('today');
        $start = $end->modify("-{$years} years");

        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $w = (int) $d->format('w');
            if ($w === 0 || $w === 6) {
                continue;
            }
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Piecewise-linear interpolation over normalized time [0,1].
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function interp(float $frac, array $points): float
    {
        if ($frac <= $points[0][0]) {
            return $points[0][1];
        }
        $last = count($points) - 1;
        if ($frac >= $points[$last][0]) {
            return $points[$last][1];
        }
        for ($i = 1; $i <= $last; $i++) {
            if ($frac <= $points[$i][0]) {
                [$x0, $y0] = $points[$i - 1];
                [$x1, $y1] = $points[$i];
                $t = ($frac - $x0) / max(1e-9, $x1 - $x0);

                return $y0 + $t * ($y1 - $y0);
            }
        }

        return $points[$last][1];
    }

    private function gauss(): float
    {
        $u1 = max(mt_rand() / mt_getrandmax(), 1e-12);
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    private function uniform(float $lo, float $hi): float
    {
        return $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);
    }

    /**
     * Macro time series for the window: Fed policy path (hold → cut → hike),
     * CPI path, index levels driven by regime-dependent factor returns, and a
     * mean-reverting VIX with spikes. Regimes ensure the model sees both
     * favorable and hostile environments.
     *
     * @param  list<string>  $days
     * @return array{rows: list<array<string, mixed>>, spx: list<float>, ndx: list<float>, rut: list<float>, vix: list<float>, fed: list<float>, cpi: list<float>, r_mkt: list<float>, sector_returns: array<int, array<string, float>>}
     */
    private function buildMacro(array $days): array
    {
        $n = count($days);

        $muPoints = [[0.0, 0.0001], [0.26, 0.0001], [0.30, 0.0009], [0.58, 0.0009],
            [0.62, 0.0001], [0.76, 0.0001], [0.80, -0.0011], [0.87, -0.0011],
            [0.91, 0.0008], [1.0, 0.0008]];
        $sigmaPoints = [[0.0, 0.011], [0.26, 0.011], [0.30, 0.008], [0.58, 0.008],
            [0.62, 0.011], [0.76, 0.011], [0.80, 0.017], [0.87, 0.017],
            [0.91, 0.010], [1.0, 0.010]];
        $vixBasePoints = [[0.0, 17.0], [0.26, 17.0], [0.32, 13.5], [0.58, 13.5],
            [0.64, 16.0], [0.76, 16.0], [0.81, 24.0], [0.87, 22.0],
            [0.92, 14.5], [1.0, 14.5]];
        $fedPoints = [[0.0, 5.5], [0.28, 5.5], [0.50, 4.0], [0.72, 4.0],
            [0.83, 4.75], [1.0, 4.75]];
        $cpiPoints = [[0.0, 3.4], [0.25, 2.9], [0.55, 2.2], [0.78, 3.3], [1.0, 2.6]];

        $rows = [];
        $spx = [];
        $ndx = [];
        $rut = [];
        $vix = [];
        $fed = [];
        $cpi = [];
        $rMkt = [];
        $sectorReturns = [];

        $spxLvl = 4300.0;
        $ndxLvl = 14600.0;
        $rutLvl = 1850.0;
        $vixLvl = 17.0;

        for ($t = 0; $t < $n; $t++) {
            $frac = $n > 1 ? $t / ($n - 1) : 0.0;
            $mu = $this->interp($frac, $muPoints);
            $sigma = $this->interp($frac, $sigmaPoints);
            $vixBase = $this->interp($frac, $vixBasePoints);

            $r = $mu + $sigma * $this->gauss();
            // Occasional shock days.
            $roll = mt_rand(0, 9999);
            if ($roll < 40) {
                $r -= $this->uniform(0.02, 0.05);
            } elseif ($roll < 70) {
                $r += $this->uniform(0.015, 0.035);
            }

            $spxLvl *= (1 + $r);
            $ndxLvl *= (1 + 1.10 * $r + 0.004 * $this->gauss());
            $rutLvl *= (1 + 1.25 * $r + 0.005 * $this->gauss());

            // VIX: Ornstein-Uhlenbeck toward regime base with jump spikes.
            $vixLvl += 0.06 * ($vixBase - $vixLvl) + 0.9 * $this->gauss();
            if (mt_rand(0, 999) < 6) {
                $vixLvl += $this->uniform(3, 11);
            }
            $vixLvl = max(9.0, min(62.0, $vixLvl));

            $fedLevel = round($this->interp($frac, $fedPoints), 2);
            $cpiLevel = max(1.2, $this->interp($frac, $cpiPoints) + 0.04 * $this->gauss());

            $secReturns = [];
            foreach ($this->sectors as $i => $sector) {
                $phase = $i * 0.7;
                $period = 120 + 17 * $i;
                $cycle = sin(2 * M_PI * $t / $period + $phase) * 0.0035;
                $secReturns[$sector['name']] = 0.85 * $r + $cycle + 0.005 * $this->gauss();
            }

            $rows[] = [
                'observation_date' => $days[$t],
                'fed_funds_rate' => $fedLevel,
                'cpi_yoy' => round($cpiLevel, 3),
                'sp500_close' => round($spxLvl, 2),
                'nasdaq_close' => round($ndxLvl, 2),
                'russell2000_close' => round($rutLvl, 2),
                'vix_close' => round($vixLvl, 2),
                'sector_sentiment' => json_encode($secReturns),
                'source' => 'synthetic',
            ];

            $spx[] = $spxLvl;
            $ndx[] = $ndxLvl;
            $rut[] = $rutLvl;
            $vix[] = $vixLvl;
            $fed[] = $fedLevel;
            $cpi[] = $cpiLevel;
            $rMkt[] = $r;
            $sectorReturns[$t] = $secReturns;
        }

        return compact('rows', 'spx', 'ndx', 'rut', 'vix', 'fed', 'cpi', 'sectorReturns')
            + ['r_mkt' => $rMkt];
    }

    private function insertMacro(array $rows): void
    {
        $now = now();
        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('macro_environment')->insertOrIgnore($chunk);
        }
    }

    /**
     * Regime attractiveness for scheduling explosive runs: runs are far more
     * likely to start when rates are falling, small caps are advancing and
     * volatility is cooling — the pattern the model must discover.
     *
     * @param  array<string, mixed>  $macro
     * @return list<float>
     */
    private function runAttractiveness(array $macro): array
    {
        $n = count($macro['fed']);
        $a = array_fill(0, $n, 1.0);

        for ($t = 0; $t < $n; $t++) {
            $fedTrend = $macro['fed'][$t] - $macro['fed'][max(0, $t - 63)];
            $rutRet = $macro['rut'][$t] / max(1e-9, $macro['rut'][max(0, $t - 20)]) - 1;
            $vixChg = $macro['vix'][$t] - $macro['vix'][max(0, $t - 10)];

            $score = 0.25;
            if ($fedTrend <= -0.15) {
                $score += 1.5;
            }
            if ($rutRet >= 0.06) {
                $score += 1.3;
            }
            if ($vixChg <= -2.0) {
                $score += 1.0;
            }
            if ($fedTrend >= 0.15) {
                $score -= 1.0;
            }
            if ($rutRet <= -0.04) {
                $score -= 0.8;
            }
            if ($vixChg >= 4.0) {
                $score -= 0.5;
            }

            $a[$t] = max(0.05, $score);
        }

        return $a;
    }

    /**
     * @param  list<float>  $weights
     */
    private function weightedSample(array $weights, int $from, int $to): ?int
    {
        if ($to <= $from || $from < 0 || $from >= count($weights)) {
            return null;
        }

        $to = min($to, count($weights) - 1);
        $total = 0.0;
        for ($i = $from; $i <= $to; $i++) {
            $total += $weights[$i];
        }

        $target = $this->uniform(0, $total);
        $acc = 0.0;
        for ($i = $from; $i <= $to; $i++) {
            $acc += $weights[$i];
            if ($acc >= $target) {
                return $i;
            }
        }

        return $to;
    }

    // ------------------------------------------------------------------
    // Per-stock generation
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $days
     * @param  array<string, mixed>  $macro
     * @param  list<float>  $attractiveness
     * @param  array<string, bool>  $usedTickers
     * @return array<string, int>
     */
    private function generateStock(int $seedOffset, array $days, array $macro, array $attractiveness, array $usedTickers): array
    {
        $n = count($days);

        $sector = $this->pickSector();
        $ticker = $this->makeTicker($sector, $usedTickers);

        $price = exp($this->uniform(log(0.05), log(4.5)));
        $mcap0 = exp($this->uniform(log(3_000_000), log(250_000_000)));
        $shares = max(1_000_000, (int) round($mcap0 / max(0.01, $price)));
        $floatRatio = $this->uniform(0.3, 0.9);

        $drift = $this->uniform(-0.0016, 0.0007);
        $vol = $this->uniform(0.035, 0.075);
        $betaMkt = $this->uniform(0.5, 2.2);
        $betaSector = $this->uniform(0.4, 1.4);
        $baseVolume = $this->uniform(120_000, 2_500_000);

        // ---- Schedule explosive runs (weighted by macro regime) ----------
        $roll = mt_rand(0, 99);
        $runCount = $roll < 62 ? 1 : ($roll < 88 ? 2 : 3);
        $runs = [];
        $lastEnd = 65;
        for ($k = 0; $k < $runCount; $k++) {
            $start = $this->weightedSample($attractiveness, $lastEnd + 30, $n - 45);
            if ($start === null) {
                break;
            }
            $dur = mt_rand(10, 35);
            $runs[] = [
                'start' => $start,
                'end' => min($n - 1, $start + $dur - 1),
                'dur' => $dur,
                'mult' => exp($this->uniform(log(2.0), log(12.0))),
                'vol_mult' => $this->uniform(3.0, 9.0),
            ];
            $lastEnd = $start + $dur - 1;
        }

        // Sometimes the stock is *entering* a run right now — gives the daily
        // inference pass candidates with live momentum.
        if (mt_rand(0, 999) < 75) {
            $start = mt_rand(max(0, $n - 14), $n - 1);
            $runs[] = [
                'start' => $start,
                'end' => $n - 1,
                'dur' => 25,
                'mult' => exp($this->uniform(log(2.5), log(9.0))),
                'vol_mult' => $this->uniform(3.5, 8.0),
            ];
        }

        usort($runs, fn ($a, $b) => $a['start'] <=> $b['start']);

        // ---- Simulate the price path ------------------------------------
        $closes = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        $events = [];

        $prevClose = $price;
        $splitDone = false;

        for ($t = 0; $t < $n; $t++) {
            $runIdx = -1;
            $inRun = false;
            $runProgress = 0.0;
            foreach ($runs as $ri => $run) {
                if ($t >= $run['start'] && $t <= $run['end']) {
                    $runIdx = $ri;
                    $inRun = true;
                    $runProgress = ($t - $run['start'] + 1) / max(1, $run['dur']);
                    break;
                }
            }

            $volMult = 1.0;

            if ($inRun) {
                $run = $runs[$runIdx];
                $driftRun = log($run['mult']) / max(1, $run['dur']);
                $r = $driftRun + $this->gauss() * $vol * 0.7;
                if (mt_rand(0, 999) < 70) {
                    $r += $this->uniform(0.03, 0.14);
                }
                $volMult = 1 + $run['vol_mult'] * sin(M_PI * $runProgress);
            } else {
                // Post-run give-back.
                $postRun = null;
                foreach ($runs as $run) {
                    if ($t > $run['end'] && $t <= $run['end'] + 14) {
                        $postRun = $run;
                    }
                }

                $sectorRet = $macro['sectorReturns'][$t][$sector['name']] ?? 0.0;
                $r = $drift
                    + $betaMkt * $macro['r_mkt'][$t]
                    + $betaSector * $sectorRet
                    + $this->gauss() * $vol;

                if ($postRun !== null) {
                    $r = -0.45 * (log($postRun['mult']) / max(1, $postRun['dur'])) + $this->gauss() * $vol * 1.4;
                    $decay = 1 - ($t - $postRun['end']) / 14;
                    $volMult = 1 + max(0.0, $decay) * 1.5;
                } elseif (mt_rand(0, 999) < 4) {
                    $r += $this->uniform(-0.30, -0.10); // dilution / failed offering
                } elseif (mt_rand(0, 999) < 4) {
                    $r += $this->uniform(0.08, 0.25); // idiosyncratic spike
                }
            }

            $close = max(0.01, $prevClose * (1 + $r));

            $open = $prevClose * (1 + $this->uniform(-0.015, 0.015));
            $high = max($open, $close) * (1 + $this->uniform(0.002, 0.035));
            $low = max(0.01, min($open, $close) * (1 - $this->uniform(0.002, 0.035)));

            $volume = (int) round(
                $baseVolume * (1 + 12 * abs($r)) * (1 + $this->uniform(-0.35, 0.5)) * $volMult
            );
            $volume = max(5_000, $volume);

            $opens[] = round(max(0.01, $open), 4);
            $highs[] = round($high, 4);
            $lows[] = round($low, 4);
            $closes[] = round($close, 4);
            $volumes[] = $volume;

            $prevClose = $close;
        }

        // ---- Corporate events -------------------------------------------
        $earningsSeq = 1;
        foreach ($runs as $k => $run) {
            $events[] = [
                'event_date' => $days[$run['start']],
                'event_type' => 'news',
                'title' => $sector['news'],
                'description' => 'Catalyst news at the start of a major run (#'.($k + 1).').',
            ];
            if (mt_rand(0, 99) < 65) {
                $offerIdx = min($n - 1, $run['start'] + (int) ceil($run['dur'] * 0.65));
                $events[] = [
                    'event_date' => $days[$offerIdx],
                    'event_type' => 'offering',
                    'title' => 'Prices registered direct offering of shares and warrants',
                    'description' => 'Financing announced mid-run — typical dilutive raise for hot penny stocks.',
                ];
            }
        }

        for ($t = 60; $t < $n; $t += 63) {
            $events[] = [
                'event_date' => $days[$t],
                'event_type' => 'earnings',
                'title' => 'Quarterly results released (Q'.$earningsSeq.')',
                'description' => null,
            ];
            $earningsSeq++;
        }

        if (! $splitDone) {
            foreach ($closes as $t => $close) {
                if ($close > 10.0) {
                    $events[] = [
                        'event_date' => $days[$t],
                        'event_type' => 'split',
                        'title' => '2-for-1 forward stock split effective',
                        'description' => 'Price series stored split-adjusted.',
                    ];
                    $splitDone = true;
                    break;
                }
            }
        }

        // ---- Persist ------------------------------------------------------
        $lastPrice = $closes[$n - 1];
        $avgVolume = (int) round(array_sum(array_slice($volumes, -20)) / min(20, $n));
        $marketCap = (int) round($lastPrice * $shares);

        $totalGain = $closes[$n - 1] / max(1e-9, $closes[0]) - 1;

        $stockId = DB::table('stocks')->insertGetId([
            'ticker' => $ticker,
            'name' => $this->companyName($sector),
            'sector' => $sector['name'],
            'industry' => $sector['industry'],
            'exchange' => mt_rand(0, 99) < 60 ? 'NASDAQ' : 'NYSE American',
            'market_cap' => $marketCap,
            'float_shares' => (int) round($shares * $floatRatio),
            'avg_volume' => $avgVolume,
            'last_price' => round($lastPrice, 4),
            'is_penny' => $lastPrice < 5.0,
            'source' => 'historical_dataset',
            'meta' => json_encode([
                'generated' => true,
                'runs' => count($runs),
                'peak_price' => round(max($closes), 4),
                'total_gain' => round($totalGain, 4),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        $priceRows = [];
        for ($t = 0; $t < $n; $t++) {
            $priceRows[] = [
                'stock_id' => $stockId,
                'trade_date' => $days[$t],
                'open' => $opens[$t],
                'high' => $highs[$t],
                'low' => $lows[$t],
                'close' => $closes[$t],
                'volume' => $volumes[$t],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($priceRows) === 500) {
                DB::table('stock_prices')->insert($priceRows);
                $priceRows = [];
            }
        }
        if ($priceRows !== []) {
            DB::table('stock_prices')->insert($priceRows);
        }

        foreach ($events as $event) {
            $event['stock_id'] = $stockId;
            $event['meta'] = null;
            $event['created_at'] = $now;
            $event['updated_at'] = $now;
            DB::table('corporate_events')->insert($event);
        }

        return [
            'ticker' => $ticker,
            'stock_id' => $stockId,
            'price_rows' => $n,
            'events' => count($events),
            'runs' => count($runs),
        ];
    }

    /** @param  array<string, bool>  $used */
    private function makeTicker(array $sector, array $used): string
    {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $hint = $sector['ticker_hints'][array_rand($sector['ticker_hints'])];
            if (mt_rand(0, 99) < 55) {
                // Hint-based: 3 hint letters + 1 random letter.
                $ticker = substr($hint, 0, 3).$letters[mt_rand(0, 25)];
            } else {
                $ticker = '';
                for ($i = 0; $i < 4; $i++) {
                    $ticker .= $letters[mt_rand(0, 25)];
                }
            }
            if (! isset($used[$ticker]) && strlen($ticker) === 4) {
                return $ticker;
            }
        }

        return substr('GEN'.str_pad((string) (count($used) + 1), 3, '0', STR_PAD_LEFT), 0, 5);
    }

    /** @return array<string, mixed> */
    private function pickSector(): array
    {
        $total = 0.0;
        foreach ($this->sectors as $s) {
            $total += $s['weight'];
        }
        $target = $this->uniform(0, $total);
        $acc = 0.0;
        foreach ($this->sectors as $s) {
            $acc += $s['weight'];
            if ($acc >= $target) {
                return $s;
            }
        }

        return $this->sectors[0];
    }

    /** @param  array<string, mixed>  $sector */
    private function companyName(array $sector): string
    {
        $prefixes = ['Nova', 'Apex', 'Quantum', 'Vertex', 'Iron', 'Polar', 'Zenith',
            'Orbit', 'Cobalt', 'Solar', 'Atlas', 'Prime', 'Flux', 'Crest', 'Bright'];
        $roots = ['Core', 'Wave', 'Peak', 'Field', 'Link', 'Star', 'Root', 'Lode',
            'Shift', 'Mark', 'Haven', 'Vale', 'Forge', 'Plex', 'Dyne'];

        $name = $prefixes[array_rand($prefixes)].$roots[array_rand($roots)].' ';
        $suffix = $this->nameSuffixes[array_rand($this->nameSuffixes)];

        // Give the suffix a bit of variety tied to the sector.
        if ($sector['name'] === 'Biotechnology' && str_contains($suffix, 'Resources')) {
            $suffix = 'Therapeutics Inc.';
        }
        if ($sector['name'] === 'Mining & Metals' && str_contains($suffix, 'Therapeutics')) {
            $suffix = 'Resources Ltd.';
        }

        return $name.$suffix;
    }
}
