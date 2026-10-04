<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Datasets\DatasetManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ImportsController extends Controller
{
    public function __construct(private readonly DatasetManager $manager) {}

    public function show(string $id): JsonResponse
    {
        $job = DB::table('import_jobs')->where('id', $id)->first();
        if ($job === null) {
            return response()->json(['code' => 'IMPORT_NOT_FOUND', 'message' => 'Задание не найдено.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $job->id,
                'dataset_id' => $job->dataset_id,
                'stage' => $job->stage,
                'status' => $job->status,
                'progress' => json_decode((string) $job->progress, true),
                'attempts' => (int) $job->attempts,
                'heartbeat_at' => $job->heartbeat_at,
                'error' => $job->error,
                'error_count' => (int) DB::table('import_errors')->where('import_job_id', $id)->count(),
                'started_at' => $job->started_at,
                'finished_at' => $job->finished_at,
            ],
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        try {
            $this->manager->cancelImport($id);
        } catch (\RuntimeException $e) {
            return response()->json(['code' => strtok($e->getMessage(), ':'), 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'cancelling']);
    }

    public function retry(string $id): JsonResponse
    {
        try {
            $this->manager->retryImport($id);
        } catch (\RuntimeException $e) {
            return response()->json(['code' => strtok($e->getMessage(), ':'), 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'queued']);
    }

    /**
     * Ошибки импорта (для «Скачать ошибки», ТЗ §5.1).
     */
    public function errors(string $id): JsonResponse
    {
        $job = DB::table('import_jobs')->where('id', $id)->first();
        if ($job === null) {
            return response()->json(['code' => 'IMPORT_NOT_FOUND', 'message' => 'Задание не найдено.'], 404);
        }
        $rows = DB::table('import_errors')->where('import_job_id', $id)->orderBy('record_no')->limit(10000)->get();

        return response()->json([
            'data' => $rows,
            'total' => $rows->count(),
            'csv_header' => ['record_no', 'column', 'code', 'message'],
        ]);
    }
}
