<?php

namespace Tests\Unit;

use App\Services\Ml\FeatureEngineer;
use App\Services\Ml\RationaleBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class FeatureEngineerTest extends TestCase
{
    public function test_gap_percentage_uses_today_open_and_prior_close(): void
    {
        $prices = $this->bars(65);
        $prices[64]['open'] = 1.12; // +12% overnight gap
        $macro = $this->macro($prices);

        $features = FeatureEngineer::compute($prices, $macro, [], ['market_cap' => 10_000_000]);

        $this->assertNotNull($features);
        $this->assertContains('gap_pct', FeatureEngineer::NAMES);
        $this->assertEqualsWithDelta(0.12, $features['gap_pct'], 1e-9);
    }

    public function test_gap_percentage_falls_back_to_zero_for_invalid_price_data(): void
    {
        $prices = $this->bars(65);
        $prices[64]['open'] = 0.0;
        $macro = $this->macro($prices);

        $features = FeatureEngineer::compute($prices, $macro, [], ['market_cap' => 10_000_000]);

        $this->assertNotNull($features);
        $this->assertSame(0.0, $features['gap_pct']);
    }

    public function test_gap_percentage_appears_in_rationales(): void
    {
        $rationale = RationaleBuilder::build(['gap_pct' => 0.12], ['gap_pct' => 1.0], []);

        $this->assertStringContainsString('Overnight gap +12.0%', $rationale);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bars(int $days): array
    {
        $bars = [];
        $date = new DateTimeImmutable('2026-01-01');

        for ($i = 0; $i < $days; $i++) {
            $bars[] = [
                'date' => $date->format('Y-m-d'),
                'open' => 1.0,
                'high' => 1.05,
                'low' => 0.95,
                'close' => 1.0,
                'volume' => 250_000,
            ];
            $date = $date->modify('+1 day');
        }

        return $bars;
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    private function macro(array $prices): array
    {
        $macro = [];

        foreach ($prices as $bar) {
            $macro[] = [
                'fed_funds_rate' => 4.5,
                'cpi_yoy' => 2.5,
                'vix_close' => 18.0,
                'sp500_close' => 5000.0,
                'nasdaq_close' => 16000.0,
                'russell2000_close' => 2000.0,
            ];
        }

        return $macro;
    }
}
