<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exports\ExportProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportsController extends Controller
{
    /**
     * POST /exports — поставить экспорт в очередь (ТЗ §9, §11).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:search_csv,niche_csv,hypothesis_md'],
            'dataset_id' => ['required_if:kind,search_csv', 'uuid'],
            'metric_run_id' => ['nullable', 'uuid'],
            'filters' => ['required_if:kind,search_csv', 'array'],
            'format' => ['nullable', 'in:tables,raw'],
            'niche_version_id' => ['required_if:kind,niche_csv', 'uuid'],
            'hypothesis_id' => ['required_if:kind,hypothesis_md', 'uuid'],
        ]);

        $jobId = ExportProcessor::queue($data['kind'], [
            'dataset_id' => $data['dataset_id'] ?? null,
            'metric_run_id' => $data['metric_run_id'] ?? null,
            'filters' => $data['filters'] ?? null,
            'format' => $data['format'] ?? 'tables',
            'niche_version_id' => $data['niche_version_id'] ?? null,
            'hypothesis_id' => $data['hypothesis_id'] ?? null,
        ]);

        return response()->json(['export_job_id' => $jobId, 'status' => 'queued'], 202);
    }

    public function show(string $id): JsonResponse
    {
        $job = DB::table('export_jobs')->where('id', $id)->first();
        if ($job === null) {
            return response()->json(['code' => 'EXPORT_NOT_FOUND', 'message' => 'Экспорт не найден.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $job->id,
                'kind' => $job->kind,
                'status' => $job->status,
                'row_count' => $job->row_count !== null ? (int) $job->row_count : null,
                'size_bytes' => $job->size_bytes !== null ? (int) $job->size_bytes : null,
                'expires_at' => $job->expires_at,
                'error' => $job->error,
                'created_at' => $job->created_at,
                'finished_at' => $job->finished_at,
            ],
        ]);
    }

    /**
     * Скачивание: файл доступен только владельцу (ТЗ §11).
     */
    public function download(string $id)
    {
        $job = DB::table('export_jobs')->where('id', $id)->where('status', 'done')->first();
        if ($job === null) {
            return response()->json(['code' => 'EXPORT_NOT_READY', 'message' => 'Экспорт не готов.'], 404);
        }
        $abs = storage_path('app/'.$job->result_path);
        if (! is_file($abs)) {
            return response()->json(['code' => 'EXPORT_FILE_MISSING', 'message' => 'Файл истёк или удалён.'], 410);
        }

        $name = 'niche-radar-'.$id.(str_ends_with((string) $job->result_path, '.md') ? '.md' : '.csv');

        return response()->download($abs, $name, [
            'Content-Type' => str_ends_with((string) $job->result_path, '.md') ? 'text/markdown' : 'text/csv',
        ]);
    }
}
