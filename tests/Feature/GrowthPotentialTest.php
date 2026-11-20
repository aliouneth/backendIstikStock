<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ml\ScoringService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrowthPotentialTest extends TestCase
{
    use RefreshDatabase;

    private function planWithFeatures(array $features): Subscription
    {
        return Subscription::query()->create([
            'name' => 'Premium',
            'slug' => 'premium',
            'description' => 'Premier plan',
            'price' => 79,
            'annual_price' => 790,
            'billing_period' => 'monthly',
            'features' => $features,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);
    }

    private function user(Subscription $plan): User
    {
        return User::query()->create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'Password123!',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'subscription_id' => $plan->id,
        ]);
    }

    private function authed(User $user): static
    {
        $token = User::issueToken();
        $user->forceFill(['api_token_hash' => $token['hash']])->save();
        $this->withHeader('Authorization', 'Bearer '.$token['plain']);

        return $this;
    }

    public function test_growth_requires_feature(): void
    {
        $plan = $this->planWithFeatures([Features::TODAY_PICKS]);
        $user = $this->user($plan);

        $this->authed($user)->getJson('/api/stocks/AACG/growth-potential')
            ->assertStatus(403);
    }

    public function test_growth_returns_potential(): void
    {
        $plan = $this->planWithFeatures([Features::GROWTH_POTENTIAL]);
        $user = $this->user($plan);

        $this->mock(ScoringService::class, function ($mock): void {
            $mock->shouldReceive('evaluateSymbol')
                ->once()
                ->with('AACG', null)
                ->andReturn([
                    'date' => '2026-09-28',
                    'model_version' => 'test-model',
                    'eligible' => true,
                    'rank' => 42,
                    'percentile' => 95,
                    'peers' => 820,
                    'ticker' => 'AACG',
                    'name' => 'ATA Creativity Global',
                    'sector' => 'Education',
                    'exchange' => 'NASDAQ',
                    'market_cap' => 1000000,
                    'item' => [
                        'rank' => 42,
                        'ticker' => 'AACG',
                        'score' => 88.5,
                        'price' => 1.02,
                        'raw_score' => 0.1,
                        'rationale' => 'Driver text.',
                        'key_features' => [],
                        'risks' => [],
                        'features' => [],
                    ],
                ]);
        });

        Stock::query()->create([
            'ticker' => 'AACG',
            'name' => 'ATA Creativity Global',
            'sector' => 'Education',
            'exchange' => 'NASDAQ',
            'last_price' => 1.02,
            'is_penny' => true,
        ]);

        $this->authed($user)->getJson('/api/stocks/AACG/growth-potential')
            ->assertOk()
            ->assertJsonPath('growth.eligible', true)
            ->assertJsonPath('growth.ticker', 'AACG');
    }
}
