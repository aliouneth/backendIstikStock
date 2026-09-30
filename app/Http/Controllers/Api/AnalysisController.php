<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Data\MacroDataService;
use App\Services\Data\MarketDataRefreshService;
use App\Services\Pipeline\DailyAnalysisService;
use App\Services\Pipeline\RecommendationReport;
use App\Support\Entitlements;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AnalysisController extends Controller
{
    public function __construct(
        private readonly DailyAnalysisService $analysis,
        private readonly RecommendationReport $report,
        private readonly MarketDataRefreshService $marketRefresh,
        private readonly MacroDataService $macroRefresh,
    ) {}

    /**
     * POST /api/run-analysis
     * Triggers data refresh → (re)train if stale → inference → persist top 5.
     */
    public function runAnalysis(Request $request): JsonResponse
    {
        $refresh = $request->boolean('refresh', true);

        try {
            $payload = $this->analysis->run($refresh);

            return response()->json(['success' => true] + $payload);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** GET /api/recommendations/today */
    public function today(Request $request): JsonResponse
    {
        $data = $this->report->today();
        if ($data === null) {
            return response()->json([
                'success' => false,
                'error' => 'No recommendations yet. Run the daily analysis first.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            ...$this->applyEntitlements($data, $request),
        ]);
    }

    /** GET /api/recommendations/history?days=30 */
    public function history(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->input('days', 30)));

        $payload = $this->applyEntitlements([
            'days' => $days,
            'history' => $this->report->history($days),
            'evaluation' => $this->report->evaluate(),
        ], $request);

        return response()->json(['success' => true, ...$payload]);
    }

    /**
     * Hide premium reasoning columns when the plan does not include them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyEntitlements(array $data, Request $request): array
    {
        if (Entitlements::allows($request->user(), Features::FULL_RATIONALE)) {
            return $data;
        }

        $redact = function (array $item): array {
            $item['rationale'] = 'Upgrade your plan to see the full model rationale.';
            $item['key_features'] = [];
            $item['rationale_locked'] = true;

            return $item;
        };

        if (isset($data['items']) && is_array($data['items'])) {
            $data['items'] = array_map($redact, $data['items']);
        }

        if (isset($data['history']) && is_array($data['history'])) {
            $data['history'] = array_map(function (array $day) use ($redact): array {
                $day['items'] = array_map($redact, $day['items'] ?? []);

                return $day;
            }, $data['history']);
        }

        return $data;
    }

    /** GET /api/model/status */
    public function modelStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'model' => $this->report->modelInfo(),
        ]);
    }

    /** GET /api/model/evaluate */
    public function evaluate(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'evaluation' => $this->report->evaluate(),
        ]);
    }

    /** POST /api/refresh-data — pull latest market + macro data only. */
    public function refreshData(): JsonResponse
    {
        try {
            $market = $this->marketRefresh->refresh();
            $macro = $this->macroRefresh->refresh();

            return response()->json(['success' => true, 'market' => $market, 'macro' => $macro]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
