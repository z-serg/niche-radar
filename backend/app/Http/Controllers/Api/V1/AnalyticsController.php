<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\CompareService;
use App\Domain\Analytics\DashboardService;
use App\Domain\Analytics\SummaryService;
use App\Domain\Datasets\DatasetManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly SummaryService $summary,
        private readonly CompareService $compare,
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * POST /analytics/summary — агрегаты выборки; тяжёлое → 202 + job_id.
     */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'metric_run_id' => ['nullable', 'uuid'],
            'filters' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->summary->summary($data['dataset_id'], $data['metric_run_id'] ?? null, $data['filters'] ?? []);
        } catch (\RuntimeException $e) {
            return response()->json(['code' => strtok($e->getMessage(), ':'), 'message' => $e->getMessage()], 422);
        }

        if (isset($result['job_id'])) {
            return response()->json(['job_id' => $result['job_id'], 'status' => 'queued'], 202);
        }

        return response()->json($result['result']);
    }

    /**
     * POST /analytics/compare — сравнение двух отчётов (ТЗ §7.5).
     */
    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_dataset_id' => ['required', 'uuid'],
            'current_dataset_id' => ['required', 'uuid', 'different:base_dataset_id'],
            'niche_id' => ['nullable', 'uuid'],
        ]);

        try {
            return response()->json($this->compare->compare(
                $data['base_dataset_id'], $data['current_dataset_id'], $data['niche_id'] ?? null
            ));
        } catch (\RuntimeException $e) {
            $code = strtok($e->getMessage(), ':');

            return response()->json(['code' => $code, 'message' => $e->getMessage()], $code === 'INCOMPATIBLE_MEASUREMENTS' ? 422 : 404);
        }
    }

    /**
     * GET /analytics/dashboard?dataset_id=… — обзор отчёта (ТЗ §5.7).
     */
    public function dashboard(Request $request): JsonResponse
    {
        $datasetId = $request->input('dataset_id') ?? DatasetManager::activeDatasetId();
        $overview = $this->dashboard->overview($datasetId);
        if ($overview === null) {
            return response()->json(['code' => 'DATASET_NOT_FOUND', 'message' => 'Активный отчёт не выбран.'], 404);
        }

        return response()->json($overview);
    }

    /**
     * GET /jobs/{id} — общий механизм заданий API и MCP (ТЗ §9).
     */
    public function jobStatus(string $id): JsonResponse
    {
        foreach (['import_jobs' => ['stage', 'progress'], 'export_jobs' => ['kind', 'result_path'], 'analytics_jobs' => ['kind', 'result']] as $table => $cols) {
            $job = DB::table($table)->where('id', $id)->first();
            if ($job !== null) {
                $payload = ['id' => $job->id, 'type' => rtrim($table, 's'), 'status' => $job->status];
                if ($table === 'analytics_jobs' && $job->status === 'done') {
                    $payload['result'] = json_decode((string) $job->result, true);
                }
                $payload['error'] = $job->error;

                return response()->json(['data' => $payload]);
            }
        }

        return response()->json(['code' => 'JOB_NOT_FOUND', 'message' => 'Задание не найдено.'], 404);
    }
}
