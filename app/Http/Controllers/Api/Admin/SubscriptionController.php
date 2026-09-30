<?php

namespace App\Http\Controllers\Api\Admin;

use App\Billing\Pricing;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    /**
     * GET /api/admin/subscriptions
     */
    public function index(): JsonResponse
    {
        $plans = Subscription::query()
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $plans->map(fn (Subscription $plan): array => $plan->toApiArray())->all(),
            'available_features' => Features::labels(),
        ]);
    }

    /**
     * POST /api/admin/subscriptions
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $plan = Subscription::query()->create([
            ...$data,
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'features' => Features::sanitize($data['features'] ?? []),
        ]);

        $this->enforceSingleDefault($plan);
        Entitlements::flushCache();

        return response()->json([
            'success' => true,
            'message' => 'Subscription created.',
            'data' => $plan->toApiArray(),
        ], 201);
    }

    /**
     * PATCH /api/admin/subscriptions/{subscription}
     */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $this->validated($request, $subscription);

        if (array_key_exists('features', $data)) {
            $data['features'] = Features::sanitize($data['features']);
        }

        if (array_key_exists('is_active', $request->all()) && ! $request->boolean('is_active')) {
            if ($subscription->is_default) {
                return $this->error('The default plan cannot be deactivated. Mark another plan as default first.');
            }

            if ($subscription->users()->where('is_active', true)->exists()) {
                return $this->error('Move active users off this plan before deactivating it.');
            }
        }

        if (array_key_exists('slug', $data) && $data['slug'] === '') {
            unset($data['slug']);
        }

        $subscription->fill($data)->save();

        $this->enforceSingleDefault($subscription);
        Entitlements::flushCache();

        return response()->json([
            'success' => true,
            'message' => 'Subscription updated.',
            'data' => $subscription->refresh()->toApiArray(),
        ]);
    }

    /**
     * DELETE /api/admin/subscriptions/{subscription}
     */
    public function destroy(Subscription $subscription): JsonResponse
    {
        if ($subscription->users()->exists()) {
            return $this->error('Reassign the users on this plan before deleting it.');
        }

        if ($subscription->is_default) {
            return $this->error('The default plan cannot be deleted. Promote another plan first.');
        }

        $subscription->delete();
        Entitlements::flushCache();

        return response()->json(['success' => true, 'message' => 'Subscription deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Subscription $subscription = null): array
    {
        $creating = $subscription === null;

        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('subscriptions', 'slug')->ignore($subscription?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:100000'],
            'annual_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'billing_period' => ['sometimes', 'string', Rule::in(Pricing::PERIODS)],
            'features' => [$creating ? 'present' : 'sometimes', 'array'],
            'features.*' => ['string', Rule::in(Features::keys())],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];

        $data = $request->validate($rules);

        foreach (['is_active', 'is_default'] as $flag) {
            if (array_key_exists($flag, $request->all())) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        // A null annual_price is meaningful: it means "derive from monthly".
        if ($request->has('annual_price')) {
            $data['annual_price'] = $request->input('annual_price') === null
                || $request->input('annual_price') === ''
                    ? null
                    : (float) $request->input('annual_price');
        }

        return $data;
    }

    private function enforceSingleDefault(Subscription $plan): void
    {
        if (! $plan->is_default) {
            return;
        }

        Subscription::query()
            ->whereKeyNot($plan->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        Entitlements::flushCache();
    }

    private function error(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $message, 'message' => $message], $status);
    }
}
