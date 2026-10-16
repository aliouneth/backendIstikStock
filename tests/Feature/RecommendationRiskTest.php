<?php

namespace Tests\Feature;

use App\Models\DailyRecommendation;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationRiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_recommendations_include_risk_flags_without_full_rationale(): void
    {
        $plan = Subscription::query()->create([
            'name' => 'Free',
            'slug' => 'free',
            'description' => 'Free plan',
            'price' => 0,
            'billing_period' => 'monthly',
            'features' => [Features::TODAY_PICKS],
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 0,
        ]);
        $user = User::query()->create([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'Password123!',
            'role' => User::ROLE_USER,
            'is_active' => true,
            'subscription_id' => $plan->id,
        ]);
        $token = User::issueToken();
        $user->forceFill(['api_token_hash' => $token['hash']])->save();

        $stock = Stock::query()->create([
            'ticker' => 'AACG',
            'name' => 'AACG Corp',
            'sector' => 'Education',
            'exchange' => 'NASDAQ',
            'last_price' => 0.75,
            'is_penny' => true,
        ]);
        DailyRecommendation::query()->create([
            'recommendation_date' => '2026-10-14',
            'stock_id' => $stock->id,
            'rank' => 1,
            'model_score' => 82.5,
            'price_at_recommendation' => 0.75,
            'rationale' => 'Test rationale.',
            'features' => ['ret_20d' => 0.21],
            'risks' => [
                [
                    'code' => 'recent_offering',
                    'label' => 'Recent offering',
                    'detail' => 'Test dilution risk.',
                ],
            ],
            'model_version' => 'test-model',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token['plain'])
            ->getJson('/api/recommendations/today')
            ->assertOk()
            ->assertJsonPath('items.0.risks.0.code', 'recent_offering')
            ->assertJsonPath('items.0.risks.0.label', 'Recent offering')
            ->assertJsonPath('items.0.key_features', []);
    }
}
