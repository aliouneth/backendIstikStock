<?php

use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\NewsScanController;
use Illuminate\Support\Facades\Route;

Route::post('/run-analysis', [AnalysisController::class, 'runAnalysis']);
Route::post('/refresh-data', [AnalysisController::class, 'refreshData']);
Route::post('/news-scan', [NewsScanController::class, 'scan']);

Route::get('/recommendations/today', [AnalysisController::class, 'today']);
Route::get('/recommendations/history', [AnalysisController::class, 'history']);

Route::get('/model/status', [AnalysisController::class, 'modelStatus']);
Route::get('/model/evaluate', [AnalysisController::class, 'evaluate']);
