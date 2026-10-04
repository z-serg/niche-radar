<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Metrics\MetricRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetricRunsController extends Controller
{
    public function __construct(private readonly MetricRunService $runs) {}

    /**
     * POST /metric-runs — запросить расчёт диапазона и профиля (ТЗ §9).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'ordinal_from' => ['nullable', 'integer', 'min:1'],
            'ordinal_to' => ['nullable', 'integer', 'min:1'],
            'profile' => ['nullable', 'array'],
        ]);

        try {
            $runId = $this->runs->requestRun(
                $data['dataset_id'],
                (int) ($data['ordinal_from'] ?? 1),
                (int) ($data['ordinal_to'] ?? 99),
                $data['profile'] ?? null,
            );
        } catch (\RuntimeException $e) {
            $code = strtok($e->getMessage(), ':');

            return response()->json(['code' => $code, 'message' => $e->getMessage()], $code === 'DATASET_NOT_READY' ? 409 : 422);
        }

        return response()->json(['metric_run_id' => $runId, 'status' => DB::table('metric_runs')->where('id', $runId)->value('status')], 202);
    }

    public function show(string $id): JsonResponse
    {
        $run = DB::table('metric_runs')->where('id', $id)->first();
        if ($run === null) {
            return response()->json(['code' => 'METRIC_RUN_NOT_FOUND', 'message' => 'Прогон не найден.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $run->id,
                'dataset_id' => $run->dataset_id,
                'status' => $run->status,
                'range' => [(int) $run->ordinal_from, (int) $run->ordinal_to],
                'algorithm_version' => $run->algorithm_version,
                'rules_version' => $run->rules_version,
                'profile' => json_decode((string) $run->profile, true),
                'results_summary' => json_decode((string) $run->results_summary, true),
                'error' => $run->error,
                'started_at' => $run->started_at,
                'completed_at' => $run->completed_at,
                'created_at' => $run->created_at,
            ],
        ]);
    }
}
