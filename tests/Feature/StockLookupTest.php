<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ml\ScoringService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockLookupTest extends TestCase
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

    public function test_search_requires_authentication(): void
    {
        // Feature-gated endpoints follow the same convention as /news-scan:
        // a guest has no subscription, so the middleware denies the feature.
        $this->getJson('/api/stocks/search?q=AAC')
            ->assertStatus(403);
    }

    public function test_search_is_denied_without_the_feature(): void
    {
        $plan = $this->planWithFeatures([Features::TODAY_PICKS]);
        $user = $this->user($plan);

        $this->authed($user)->getJson('/api/stocks/search?q=AAC')
            ->assertStatus(403);
    }

    public function test_search_finds_a_ticker_and_a_name(): void
    {
        $plan = $this->planWithFeatures([Features::STOCK_LOOKUP]);
        $user = $this->user($plan);

        Stock::query()->create([
            'ticker' => 'AACG',
            'name' => 'ATA Creativity Global',
            'sector' => 'Education',
            'exchange' => 'NASDAQ',
            'last_price' => 1.02,
            'is_penny' => true,
        ]);

        $this->authed($user)->getJson('/api/stocks/search?q=aac')
            ->assertOk()
            ->assertJsonPath('results.0.ticker', 'AACG')
            ->assertJsonPath('results.0.sector', 'Education');
    }

    public function test_empty_or_blank_query_returns_empty_results(): void
    {
        $plan = $this->planWithFeatures([Features::STOCK_LOOKUP]);
        $user = $this->user($plan);

        $this->authed($user)->getJson('/api/stocks/search')
            ->assertOk()
            ->assertJson(['results' => []]);
    }

    public function test_evaluate_passes_ticker_to_scoring_service(): void
    {
        $plan = $this->planWithFeatures([Features::STOCK_LOOKUP]);
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
                    'item' => [
                        'rank' => 42,
                        'ticker' => 'AACG',
                        'score' => 88.5,
                        'rationale' => 'Driver text.',
                        'key_features' => [],
                    ],
                ]);
        });

        $this->authed($user)->getJson('/api/stocks/AACG/evaluation')
            ->assertOk()
            ->assertJsonPath('evaluation.eligible', true)
            ->assertJsonPath('evaluation.rank', 42)
            ->assertJsonPath('evaluation.percentile', 95)
            ->assertJsonPath('evaluation.item.score', 88.5);
    }

    public function test_evaluate_rejects_malformed_tickers(): void
    {
        $plan = $this->planWithFeatures([Features::STOCK_LOOKUP]);
        $user = $this->user($plan);

        $this->authed($user)->getJson('/api/stocks/THIS%20IS%20NOT%20A%20TICKER/evaluation')
            ->assertStatus(422);
    }

    public function test_evaluate_maps_unknown_ticker_to_404(): void
    {
        $plan = $this->planWithFeatures([Features::STOCK_LOOKUP]);
        $user = $this->user($plan);

        $this->mock(ScoringService::class, function ($mock): void {
            $mock->shouldReceive('evaluateSymbol')
                ->once()
                ->with('ZZZZ', null)
                ->andThrow(new \RuntimeException('Unknown ticker [ZZZZ].'));
        });

        $this->authed($user)->getJson('/api/stocks/ZZZZ/evaluation')
            ->assertStatus(404);
    }

    public function test_evaluate_surfaces_scoring_failures(): void
    {
        $plan = $this->planWithFeatures([Features::STOCK_LOOKUP]);
        $user = $this->user($plan);

        $this->mock(ScoringService::class, function ($mock): void {
            $mock->shouldReceive('evaluateSymbol')
                ->once()
                ->andThrow(new \RuntimeException('No trained model found.'));
        });

        $this->authed($user)->getJson('/api/stocks/PENNY/evaluation')
            ->assertStatus(503);
    }
}
