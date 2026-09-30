<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function plan(
        ?string $name = null,
        ?array $features = null,
        bool $isDefault = true,
    ): Subscription {
        return Subscription::query()->create([
            'name' => $name ?? 'Free',
            'slug' => str($name ?? 'Free')->slug()->value(),
            'description' => 'Test plan',
            'price' => 0,
            'billing_period' => 'monthly',
            'features' => $features ?? [Features::TODAY_PICKS],
            'is_active' => true,
            'is_default' => $isDefault,
            'sort_order' => 0,
        ]);
    }

    private function user(?Subscription $plan = null, array $overrides = []): User
    {
        return User::query()->create([
            'name' => $overrides['name'] ?? 'Test User',
            'email' => $overrides['email'] ?? 'user@example.com',
            'password' => 'Password123!',
            'role' => $overrides['role'] ?? User::ROLE_USER,
            'is_active' => $overrides['is_active'] ?? true,
            'subscription_id' => $plan?->id,
        ]);
    }

    public function test_guests_receive_default_plan_features(): void
    {
        $this->plan(features: [Features::TODAY_PICKS, Features::HISTORY]);

        $this->getJson('/api/access')
            ->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('features', [Features::TODAY_PICKS, Features::HISTORY]);
    }

    public function test_guests_are_blocked_from_premium_features(): void
    {
        $this->plan(features: [Features::TODAY_PICKS]);

        $this->postJson('/api/news-scan')
            ->assertStatus(403)
            ->assertJsonPath('upgrade_required', true)
            ->assertJsonPath('feature', Features::NEWS_SURGE);
    }

    public function test_registration_issues_token_and_assigns_default_plan(): void
    {
        $this->plan();

        $res = $this->postJson('/api/auth/register', [
            'name' => 'Dana',
            'email' => 'dana@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $this->assertNotEmpty($res->json('token'));
        $this->assertSame('dana@example.com', $res->json('user.email'));
        $this->assertSame('Free', $res->json('user.subscription.name'));

        $this->withHeader('Authorization', 'Bearer '.$res->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'dana@example.com');
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->user();

        $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_plan_features_drive_api_access(): void
    {
        $free = $this->plan(features: [Features::TODAY_PICKS]);
        $user = $this->user($free);

        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/news-scan')
            ->assertStatus(403);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $user = $this->user($this->plan());
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_deactivated_accounts_cannot_authenticate(): void
    {
        $user = $this->user($this->plan(), ['is_active' => false]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_administrators_bypass_feature_gates(): void
    {
        $user = $this->user($this->plan(features: []), ['role' => User::ROLE_ADMIN]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/access')
            ->assertOk()
            ->assertJsonPath('features', Features::keys());
    }

    public function test_admin_routes_require_a_token(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/admin/subscriptions')->assertStatus(401);
    }

    public function test_non_admins_are_forbidden_from_admin_routes(): void
    {
        $token = $this->tokenFor($this->user($this->plan()));

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_admin_can_create_a_subscription_with_features(): void
    {
        $token = $this->tokenFor(
            $this->user($this->plan(), ['role' => User::ROLE_ADMIN])
        );

        $res = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/admin/subscriptions', [
                'name' => 'Day Trader',
                'price' => 49,
                'features' => [Features::NEWS_SURGE, Features::TODAY_PICKS],
            ])
            ->assertCreated();

        $this->assertSame('day-trader', $res->json('data.slug'));
        $this->assertSame(
            [Features::NEWS_SURGE, Features::TODAY_PICKS],
            $res->json('data.features')
        );
    }

    public function test_unknown_features_are_rejected(): void
    {
        $token = $this->tokenFor(
            $this->user($this->plan(), ['role' => User::ROLE_ADMIN])
        );

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/admin/subscriptions', [
                'name' => 'Odd',
                'price' => 5,
                'features' => [Features::TODAY_PICKS, 'not_a_real_feature'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('features.1');
    }

    public function test_sanitize_drops_unknown_and_duplicate_features(): void
    {
        $this->assertSame(
            [Features::TODAY_PICKS],
            Features::sanitize([Features::TODAY_PICKS, Features::TODAY_PICKS, 'nope', 42])
        );
    }

    public function test_admin_can_assign_a_subscription_to_a_user(): void
    {
        $plan = $this->plan(features: [Features::NEWS_SURGE]);
        $token = $this->tokenFor(
            $this->user(null, ['email' => 'admin@example.com', 'role' => User::ROLE_ADMIN])
        );
        $target = $this->user();

        $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/admin/users/{$target->id}", ['subscription_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.subscription.name', 'Free');
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->user($this->plan(), ['role' => User::ROLE_ADMIN]);
        $token = $this->tokenFor($admin);

        $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_plans_with_users_cannot_be_deleted(): void
    {
        $plan = $this->plan();
        $this->user($plan);
        $token = $this->tokenFor(
            $this->user(null, ['email' => 'admin@example.com', 'role' => User::ROLE_ADMIN])
        );

        $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/admin/subscriptions/{$plan->id}")
            ->assertStatus(422);
    }

    public function test_expired_subscriptions_lose_access(): void
    {
        $plan = $this->plan(features: [Features::TODAY_PICKS]);
        $user = $this->user($plan);
        $user->forceFill(['subscription_expires_at' => now()->subDay()])->save();

        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/access')
            ->assertOk()
            ->assertJsonPath('features', []);
    }

    private function tokenFor(User $user): string
    {
        $token = User::issueToken();
        $user->forceFill(['api_token_hash' => $token['hash']])->save();

        return $token['plain'];
    }
}
