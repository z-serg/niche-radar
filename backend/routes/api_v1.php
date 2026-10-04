<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 (ТЗ §9)
|--------------------------------------------------------------------------
| Локальное приложение без аутентификации: все маршруты публичны.
| MCP-сервер — всегда read-only (см. app/Mcp).
*/

Route::prefix('v1')->group(function () {

    // --- Диагностика ---
    Route::get('/health', [V1\HealthController::class, 'health']);
    Route::get('/ping', [V1\HealthController::class, 'ping']);

    // --- Чтение ---
    Route::get('/datasets', [V1\DatasetsController::class, 'index']);
    Route::get('/datasets/{id}', [V1\DatasetsController::class, 'show']);
    Route::get('/datasets/{id}/preview', [V1\DatasetsController::class, 'preview']);
    Route::get('/imports/{id}', [V1\ImportsController::class, 'show']);
    Route::get('/imports/{id}/errors', [V1\ImportsController::class, 'errors']);
    Route::get('/metric-runs/{id}', [V1\MetricRunsController::class, 'show']);
    Route::post('/keywords/search', [V1\SearchController::class, 'search']);
    Route::post('/keywords/count', [V1\SearchController::class, 'count']);
    Route::get('/keywords/{id}', [V1\KeywordsController::class, 'show']);
    Route::get('/keywords/{id}/history', [V1\KeywordsController::class, 'history']);
    Route::post('/analytics/summary', [V1\AnalyticsController::class, 'summary']);
    Route::post('/analytics/compare', [V1\AnalyticsController::class, 'compare']);
    Route::get('/analytics/dashboard', [V1\AnalyticsController::class, 'dashboard']);
    Route::get('/jobs/{id}', [V1\AnalyticsController::class, 'jobStatus']);
    Route::get('/candidate-groups', [V1\CandidateGroupsController::class, 'index']);
    Route::get('/candidate-groups/{id}/members', [V1\CandidateGroupsController::class, 'members']);
    Route::get('/saved-searches', [V1\SavedSearchesController::class, 'index']);
    Route::get('/saved-searches/{id}', [V1\SavedSearchesController::class, 'show']);
    Route::get('/niches', [V1\NichesController::class, 'index']);
    Route::get('/niches/{id}', [V1\NichesController::class, 'show']);
    Route::get('/niches/{id}/members', [V1\NichesController::class, 'members']);
    Route::get('/hypotheses', [V1\HypothesesController::class, 'index']);
    Route::get('/hypotheses/{id}', [V1\HypothesesController::class, 'show']);
    Route::get('/exports/{id}', [V1\ExportsController::class, 'show']);
    Route::get('/exports/{id}/download', [V1\ExportsController::class, 'download']);
    Route::get('/brands', [V1\BrandsController::class, 'index']);
    Route::get('/brands/{id}', [V1\BrandsController::class, 'show']);
    Route::get('/presets', [V1\PresetsController::class, 'index']);

    // --- Мутации ---
    Route::post('/datasets', [V1\DatasetsController::class, 'store']);
    Route::put('/datasets/{id}/mapping', [V1\DatasetsController::class, 'updateMapping']);
    Route::post('/datasets/{id}/import', [V1\DatasetsController::class, 'import']);
    Route::patch('/datasets/{id}', [V1\DatasetsController::class, 'update']);
    Route::delete('/datasets/{id}', [V1\DatasetsController::class, 'destroy']);
    Route::post('/imports/{id}/cancel', [V1\ImportsController::class, 'cancel']);
    Route::post('/imports/{id}/retry', [V1\ImportsController::class, 'retry']);
    Route::post('/metric-runs', [V1\MetricRunsController::class, 'store']);

    Route::post('/keywords/{id}/labels', [V1\LabelsController::class, 'addLabel']);
    Route::delete('/keywords/{id}/labels', [V1\LabelsController::class, 'removeLabel']);
    Route::post('/keywords/{id}/notes', [V1\LabelsController::class, 'addNote']);
    Route::delete('/keywords/{id}/notes/{noteId}', [V1\LabelsController::class, 'removeNote']);

    Route::post('/saved-searches', [V1\SavedSearchesController::class, 'store']);
    Route::patch('/saved-searches/{id}', [V1\SavedSearchesController::class, 'update']);
    Route::delete('/saved-searches/{id}', [V1\SavedSearchesController::class, 'destroy']);

    Route::post('/niches', [V1\NichesController::class, 'store']);
    Route::patch('/niches/{id}', [V1\NichesController::class, 'update']);
    Route::delete('/niches/{id}', [V1\NichesController::class, 'destroy']);
    Route::post('/niches/{id}/evaluate', [V1\NichesController::class, 'evaluate']);

    Route::post('/hypotheses', [V1\HypothesesController::class, 'store']);
    Route::patch('/hypotheses/{id}', [V1\HypothesesController::class, 'update']);
    Route::delete('/hypotheses/{id}', [V1\HypothesesController::class, 'destroy']);
    Route::post('/hypotheses/{id}/evidence', [V1\HypothesesController::class, 'addEvidence']);
    Route::delete('/hypotheses/{id}/evidence/{keywordId}', [V1\HypothesesController::class, 'removeEvidence']);
    Route::post('/hypotheses/{id}/export', [V1\HypothesesController::class, 'export']);

    Route::post('/exports', [V1\ExportsController::class, 'store']);
    Route::post('/brands', [V1\BrandsController::class, 'store']);
    Route::patch('/brands/{id}', [V1\BrandsController::class, 'update']);
    Route::delete('/brands/{id}', [V1\BrandsController::class, 'destroy']);
});
