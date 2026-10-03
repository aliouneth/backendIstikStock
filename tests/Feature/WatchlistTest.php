<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\Ml\ScoringService;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlistTest extends TestCase
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

    private function user(Subscription $plan, string $email = 'member@example.com'): User
    {
        return User::query()->create([
            'name' => 'Member',
            'email' => $email,
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

    private function stock(string $ticker = 'AACG'): Stock
    {
        return Stock::query()->create([
            'ticker' => $ticker,
            'name' => $ticker.' Corp',
            'sector' => 'Education',
            'exchange' => 'NASDAQ',
            'last_price' => 1.02,
            'is_penny' => true,
        ]);
    }

    /* ------------------------------ access ------------------------------ */

    public function test_index_requires_the_feature(): void
    {
        $this->getJson('/api/watchlists')->assertStatus(403);
    }

    public function test_index_is_denied_without_the_feature(): void
    {
        $user = $this->user($this->planWithFeatures([Features::TODAY_PICKS]));

        $this->authed($user)->getJson('/api/watchlists')->assertStatus(403);
    }

    public function test_admin_is_allowed(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->authed($admin)->getJson('/api/watchlists')->assertOk();
    }

    /* ------------------------------ lists ------------------------------ */

    public function test_store_creates_a_named_list(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));

        $this->authed($user)->postJson('/api/watchlists', ['name' => 'Long term'])
            ->assertCreated()
            ->assertJsonPath('watchlist.name', 'Long term')
            ->assertJsonPath('watchlist.items_count', 0);

        $this->assertDatabaseHas('watchlists', [
            'user_id' => $user->id,
            'name' => 'Long term',
        ]);
    }

    public function test_store_requires_a_name(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));

        $this->authed($user)->postJson('/api/watchlists', [])->assertStatus(422);
    }

    public function test_store_rejects_a_duplicate_name_for_the_same_user(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));

        $this->authed($user)->postJson('/api/watchlists', ['name' => 'Ideas'])->assertCreated();
        $this->authed($user)->postJson('/api/watchlists', ['name' => 'Ideas'])->assertStatus(422);
    }

    public function test_index_lists_only_the_callers_lists(): void
    {
        $plan = $this->planWithFeatures([Features::WATCHLISTS]);
        $mine = $this->user($plan, 'mine@example.com');
        $theirs = $this->user($plan, 'theirs@example.com');

        Watchlist::query()->create(['user_id' => $mine->id, 'name' => 'Mine']);
        Watchlist::query()->create(['user_id' => $theirs->id, 'name' => 'Theirs']);

        $this->authed($mine)->getJson('/api/watchlists')
            ->assertOk()
            ->assertJsonCount(1, 'watchlists')
            ->assertJsonPath('watchlists.0.name', 'Mine');
    }

    public function test_update_renames_a_list(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Old']);

        $this->authed($user)->patchJson("/api/watchlists/{$list->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('watchlist.name', 'New');
    }

    public function test_destroy_removes_a_list(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Temp']);

        $this->authed($user)->deleteJson("/api/watchlists/{$list->id}")->assertOk();
        $this->assertDatabaseMissing('watchlists', ['id' => $list->id]);
    }

    public function test_another_users_list_is_not_found(): void
    {
        $plan = $this->planWithFeatures([Features::WATCHLISTS]);
        $owner = $this->user($plan, 'owner@example.com');
        $intruder = $this->user($plan, 'intruder@example.com');
        $list = Watchlist::query()->create(['user_id' => $owner->id, 'name' => 'Private']);

        $this->authed($intruder)->getJson("/api/watchlists/{$list->id}")->assertStatus(404);
        $this->authed($intruder)->patchJson("/api/watchlists/{$list->id}", ['name' => 'Hijack'])->assertStatus(404);
        $this->authed($intruder)->deleteJson("/api/watchlists/{$list->id}")->assertStatus(404);
    }

    /* ------------------------------- items ------------------------------ */

    public function test_item_can_be_added_and_removed(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Picks']);
        $stock = $this->stock();

        $res = $this->authed($user)->postJson("/api/watchlists/{$list->id}/items", ['ticker' => 'aacg'])
            ->assertCreated()
            ->assertJsonPath('item.ticker', 'AACG');

        $this->assertDatabaseHas('watchlist_items', [
            'watchlist_id' => $list->id,
            'stock_id' => $stock->id,
        ]);

        $itemId = $res->json('item.id');
        $this->authed($user)->deleteJson("/api/watchlists/{$list->id}/items/{$itemId}")->assertOk();
        $this->assertDatabaseMissing('watchlist_items', ['id' => $itemId]);
    }

    public function test_adding_an_unknown_ticker_is_404(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Picks']);

        $this->authed($user)->postJson("/api/watchlists/{$list->id}/items", ['ticker' => 'ZZZZ'])
            ->assertStatus(404);
    }

    public function test_adding_a_malformed_ticker_is_422(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Picks']);

        $this->authed($user)->postJson("/api/watchlists/{$list->id}/items", ['ticker' => 'not a ticker'])
            ->assertStatus(422);
    }

    public function test_adding_the_same_ticker_twice_is_rejected(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Picks']);
        $this->stock();

        $this->authed($user)->postJson("/api/watchlists/{$list->id}/items", ['ticker' => 'AACG'])->assertCreated();
        $this->authed($user)->postJson("/api/watchlists/{$list->id}/items", ['ticker' => 'AACG'])->assertStatus(422);
    }

    public function test_items_are_scoped_to_the_list(): void
    {
        $plan = $this->planWithFeatures([Features::WATCHLISTS]);
        $owner = $this->user($plan, 'owner@example.com');
        $intruder = $this->user($plan, 'intruder@example.com');

        $list = Watchlist::query()->create(['user_id' => $owner->id, 'name' => 'Private']);
        $stock = $this->stock();
        $item = WatchlistItem::query()->create([
            'watchlist_id' => $list->id,
            'stock_id' => $stock->id,
        ]);

        $this->authed($intruder)->deleteJson("/api/watchlists/{$list->id}/items/{$item->id}")
            ->assertStatus(404);
        $this->assertDatabaseHas('watchlist_items', ['id' => $item->id]);
    }

    /* ----------------------------- tracking ----------------------------- */

    public function test_show_returns_items_with_price_history_and_evaluation(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Tracked']);
        $stock = $this->stock();

        $stock->prices()->create([
            'trade_date' => '2026-09-28',
            'open' => 1.0,
            'high' => 1.05,
            'low' => 0.99,
            'close' => 1.02,
            'volume' => 250000,
        ]);

        WatchlistItem::query()->create([
            'watchlist_id' => $list->id,
            'stock_id' => $stock->id,
        ]);

        $this->mock(ScoringService::class, function ($mock): void {
            $mock->shouldReceive('evaluateSymbols')
                ->once()
                ->with(['AACG'])
                ->andReturn([
                    'date' => '2026-09-28',
                    'model_version' => 'test-model',
                    'universe' => 1200,
                    'scored' => 800,
                    'skipped' => 400,
                    'evaluations' => [
                        'AACG' => [
                            'date' => '2026-09-28',
                            'model_version' => 'test-model',
                            'universe' => 1200,
                            'scored' => 800,
                            'skipped' => 400,
                            'ticker' => 'AACG',
                            'name' => 'AACG Corp',
                            'sector' => 'Education',
                            'exchange' => 'NASDAQ',
                            'market_cap' => null,
                            'eligible' => true,
                            'rank' => 7,
                            'percentile' => 99.1,
                            'peers' => 800,
                            'item' => [
                                'rank' => 7,
                                'ticker' => 'AACG',
                                'score' => 91.2,
                                'rationale' => 'Momentum with volume confirmation.',
                                'key_features' => ['ret_20d' => 0.21],
                            ],
                        ],
                    ],
                ]);
        });

        $this->authed($user)->getJson("/api/watchlists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('watchlist.name', 'Tracked')
            ->assertJsonPath('watchlist.items_count', 1)
            ->assertJsonPath('items.0.ticker', 'AACG')
            ->assertJsonPath('items.0.evaluation.eligible', true)
            ->assertJsonPath('items.0.evaluation.rank', 7)
            ->assertJsonPath('items.0.price_history.0.close', 1.02);
    }

    public function test_show_degrades_gracefully_when_scoring_fails(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Tracked']);
        $stock = $this->stock();

        WatchlistItem::query()->create([
            'watchlist_id' => $list->id,
            'stock_id' => $stock->id,
        ]);

        $this->mock(ScoringService::class, function ($mock): void {
            $mock->shouldReceive('evaluateSymbols')
                ->once()
                ->andThrow(new \RuntimeException('No trained model found.'));
        });

        $this->authed($user)->getJson("/api/watchlists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('items.0.ticker', 'AACG')
            ->assertJsonPath('items.0.evaluation', null);
    }

    public function test_show_does_not_score_an_empty_list(): void
    {
        $user = $this->user($this->planWithFeatures([Features::WATCHLISTS]));
        $list = Watchlist::query()->create(['user_id' => $user->id, 'name' => 'Empty']);

        $this->mock(ScoringService::class, function ($mock): void {
            $mock->shouldNotReceive('evaluateSymbols');
        });

        $this->authed($user)->getJson("/api/watchlists/{$list->id}")
            ->assertOk()
            ->assertJsonPath('watchlist.items_count', 0)
            ->assertJsonPath('items', []);
    }
}
