<?php

namespace Tests\Unit;

use App\Services\Ml\RiskAssessor;
use PHPUnit\Framework\TestCase;

class RiskAssessorTest extends TestCase
{
    public function test_it_flags_material_liquidity_volatility_and_financing_risks(): void
    {
        $risks = RiskAssessor::assess([
            'vol_20d' => 0.18,
            'beta_rut_20' => 2.5,
            'offering_recent_30d' => 1.0,
            'gap_pct' => 0.12,
            'down_days_ratio_10' => 0.9,
        ], 0.75, 200_000, 20_000_000);

        $this->assertSame([
            'sub_dollar_price',
            'thin_dollar_liquidity',
            'high_volatility',
            'high_small_cap_beta',
            'recent_offering',
            'extended_overnight_gap',
            'persistent_selling',
            'nano_cap',
        ], array_column($risks, 'code'));
    }

    public function test_it_returns_no_flags_for_a_liquid_low_stress_candidate(): void
    {
        $risks = RiskAssessor::assess([
            'vol_20d' => 0.05,
            'beta_rut_20' => 1.2,
            'offering_recent_30d' => 0.0,
            'gap_pct' => 0.02,
            'down_days_ratio_10' => 0.3,
        ], 2.00, 500_000, 100_000_000);

        $this->assertSame([], $risks);
    }

    public function test_it_ignores_missing_or_non_positive_inputs(): void
    {
        $this->assertSame([], RiskAssessor::assess([], 0.0, 0.0, 0.0));
        $this->assertSame([], RiskAssessor::assess([], 2.00, 500_000, null));
    }
}
