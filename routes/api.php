<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\NewsScanController;
use App\Http\Controllers\Api\StockLookupController;
use App\Http\Controllers\Api\WatchlistController;
use App\Support\Features;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public access & authentication
|--------------------------------------------------------------------------
*/
Route::middleware('auth.optional')->group(function (): void {
    Route::get('/access', [AccessController::class, 'show']);
});
Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth.token')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

/*
|--------------------------------------------------------------------------
| Analysis & news — availability depends on subscription features
|--------------------------------------------------------------------------
*/
Route::middleware('auth.optional')->group(function (): void {
    Route::post('/run-analysis', [AnalysisController::class, 'runAnalysis'])
        ->middleware('feature:'.Features::DAILY_ANALYSIS);
    Route::post('/refresh-data', [AnalysisController::class, 'refreshData'])
        ->middleware(['auth.token', 'admin']);
    Route::post('/news-scan', [NewsScanController::class, 'scan'])
        ->middleware('feature:'.Features::NEWS_SURGE);

    Route::get('/recommendations/today', [AnalysisController::class, 'today'])
        ->middleware('feature:'.Features::TODAY_PICKS);
    Route::get('/recommendations/history', [AnalysisController::class, 'history'])
        ->middleware('feature:'.Features::HISTORY);

    Route::get('/model/status', [AnalysisController::class, 'modelStatus']);
    Route::get('/model/evaluate', [AnalysisController::class, 'evaluate'])
        ->middleware('feature:'.Features::MODEL_METRICS);

    // Single-stock model lookup. `search` is declared before the {ticker}
    // wildcard so it is not swallowed by the parameterised route.
    Route::get('/stocks/search', [StockLookupController::class, 'search'])
        ->middleware('feature:'.Features::STOCK_LOOKUP);
    Route::get('/stocks/{ticker}/evaluation', [StockLookupController::class, 'evaluate'])
        ->middleware('feature:'.Features::STOCK_LOOKUP);

    // Premium watchlists: named lists of tracked stocks with per-item
    // price history and model evaluation.
    Route::middleware('feature:'.Features::WATCHLISTS)->group(function (): void {
        Route::get('/watchlists', [WatchlistController::class, 'index']);
        Route::post('/watchlists', [WatchlistController::class, 'store']);
        Route::get('/watchlists/{watchlist}', [WatchlistController::class, 'show']);
        Route::patch('/watchlists/{watchlist}', [WatchlistController::class, 'update']);
        Route::delete('/watchlists/{watchlist}', [WatchlistController::class, 'destroy']);

        Route::post('/watchlists/{watchlist}/items', [WatchlistController::class, 'storeItem']);
        Route::delete('/watchlists/{watchlist}/items/{item}', [WatchlistController::class, 'destroyItem']);
    });
});

/*
|--------------------------------------------------------------------------
| Billing & checkout
|--------------------------------------------------------------------------
| A user may only ever see and settle their own checkout. The plan is granted
| by CheckoutService after the configured driver confirms payment.
*/
Route::prefix('billing')->middleware('auth.token')->group(function (): void {
    Route::get('/quote', [CheckoutController::class, 'quote']);
    Route::get('/subscription', [CheckoutController::class, 'subscription']);
    Route::post('/checkouts', [CheckoutController::class, 'store']);
    Route::get('/checkouts/{checkout}', [CheckoutController::class, 'show']);
    Route::post('/checkouts/{checkout}/confirm', [CheckoutController::class, 'confirm']);
    Route::post('/checkouts/{checkout}/cancel', [CheckoutController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth.token', 'admin'])->group(function (): void {
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::patch('/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);

    Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
    Route::post('/subscriptions', [AdminSubscriptionController::class, 'store']);
    Route::patch('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'update']);
    Route::delete('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'destroy']);
});
