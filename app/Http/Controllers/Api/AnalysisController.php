<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Data\MacroDataService;
use App\Services\Data\MarketDataRefreshService;
use App\Services\Pipeline\DailyAnalysisService;
use App\Services\Pipeline\RecommendationReport;
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
    public function today(): JsonResponse
    {
        $data = $this->report->today();
        if ($data === null) {
            return response()->json([
                'success' => false,
                'error' => 'No recommendations yet. Run the daily analysis first.',
            ], 404);
        }

        return response()->json(['success' => true] + $data);
    }

    /** GET /api/recommendations/history?days=30 */
    public function history(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->input('days', 30)));

        return response()->json([
            'success' => true,
            'days' => $days,
            'history' => $this->report->history($days),
            'evaluation' => $this->report->evaluate(),
        ]);
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
