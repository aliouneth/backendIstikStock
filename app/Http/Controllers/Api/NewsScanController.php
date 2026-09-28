<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\News\NewsSurgeScanner;
use Illuminate\Http\JsonResponse;
use Throwable;

class NewsScanController extends Controller
{
    public function __construct(private readonly NewsSurgeScanner $scanner) {}

    /**
     * POST /api/news-scan
     * Top-N stocks at the intersection of model strength and fresh catalyst
     * news, with reasoning and short-horizon target sell prices.
     */
    public function scan(): JsonResponse
    {
        try {
            $payload = $this->scanner->scan();

            return response()->json(['success' => true] + $payload);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
