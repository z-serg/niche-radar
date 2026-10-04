<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Datasets\DatasetManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatasetsController extends Controller
{
    public function __construct(private readonly DatasetManager $manager) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('datasets as d')
            ->join('source_files as f', 'f.id', '=', 'd.source_file_id')
            ->leftJoin('import_jobs as ij', 'ij.id', '=', DB::raw('(SELECT id FROM import_jobs WHERE dataset_id = d.id ORDER BY created_at DESC LIMIT 1)'))
            ->orderByDesc('d.created_at')
            ->get(['d.*', 'f.original_name', 'f.size_bytes', 'f.sha256', 'ij.status as import_status', 'ij.stage as import_stage', 'ij.progress', 'ij.error as import_error']);

        return response()->json([
            'data' => $rows->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'file' => ['name' => $d->original_name, 'size_bytes' => (int) $d->size_bytes, 'sha256' => $d->sha256],
                'uploaded_at' => $d->created_at,
                'source_date_claimed' => null,
                'region' => $d->region,
                'row_count' => $d->row_count !== null ? (int) $d->row_count : null,
                'scan_count' => $d->scan_count !== null ? (int) $d->scan_count : null,
                'coverage_type' => $d->coverage_type,
                'status' => $d->status,
                'is_active' => (bool) $d->is_active,
                'is_archived' => (bool) $d->is_archived,
                'revision' => (int) $d->revision,
                'quality_flags' => json_decode((string) $d->quality_flags, true) ?: [],
                'import' => $d->import_status !== null ? [
                    'status' => $d->import_status,
                    'stage' => $d->import_stage,
                    'progress' => json_decode((string) $d->progress, true),
                    'error' => $d->import_error,
                ] : null,
            ])->values(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $d = DB::table('datasets as d')
            ->join('source_files as f', 'f.id', '=', 'd.source_file_id')
            ->where('d.id', $id)
            ->first();
        if ($d === null) {
            return response()->json(['code' => 'DATASET_NOT_FOUND', 'message' => 'Отчёт не найден.'], 404);
        }

        $scans = DB::table('dataset_scans')->where('dataset_id', $id)->orderBy('ordinal')->get();
        $run = DB::table('metric_runs')->where('dataset_id', $id)->where('is_default', true)->orderByDesc('created_at')->first();

        return response()->json([
            'data' => [
                'id' => $d->id,
                'title' => $d->title,
                'status' => $d->status,
                'coverage_type' => $d->coverage_type,
                'region' => $d->region,
                'frequency_definition' => $d->frequency_definition,
                'period_description' => $d->period_description,
                'row_count' => $d->row_count !== null ? (int) $d->row_count : null,
                'scan_count' => $d->scan_count !== null ? (int) $d->scan_count : null,
                'is_active' => (bool) $d->is_active,
                'revision' => (int) $d->revision,
                'original_dataset_id' => $d->original_dataset_id,
                'quality_flags' => json_decode((string) $d->quality_flags, true) ?: [],
                'mapping' => json_decode((string) $d->mapping, true),
                'policy' => json_decode((string) $d->policy, true),
                'file' => ['name' => $d->original_name, 'size_bytes' => (int) $d->size_bytes, 'sha256' => $d->sha256, 'uploaded_at' => $d->uploaded_at],
                'scans' => $scans->map(fn ($s) => [
                    'id' => $s->id,
                    'ordinal' => (int) $s->ordinal,
                    'source_header' => $s->source_header,
                    'source_suffix' => $s->source_suffix,
                    'scan_date' => $s->scan_date,
                    'metadata' => json_decode((string) $s->metadata, true),
                ]),
                'metric_run' => $run ? [
                    'id' => $run->id,
                    'status' => $run->status,
                    'algorithm_version' => $run->algorithm_version,
                    'rules_version' => $run->rules_version,
                    'range' => [(int) $run->ordinal_from, (int) $run->ordinal_to],
                    'results_summary' => json_decode((string) $run->results_summary, true),
                ] : null,
            ],
        ]);
    }

    /**
     * Загрузка CSV (ТЗ §9: вернуть ID и статус без ожидания импорта).
     */
    public function store(Request $request): JsonResponse
    {
        $maxBytes = (int) config('niche.upload_max_bytes');
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ($maxBytes / 1024), 'mimes:csv,txt', 'extensions:csv,txt'],
            'title' => ['nullable', 'string', 'max:200'],
            'coverage_type' => ['nullable', 'in:unknown,full_top,sample,filtered'],
            'region' => ['nullable', 'string', 'max:100'],
            'period_description' => ['nullable', 'string', 'max:300'],
            'new_revision' => ['nullable', 'boolean'],
            'skip_invalid' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $result = $this->manager->ingestFile(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            [
                'title' => $request->input('title'),
                'coverage_type' => $request->input('coverage_type', 'unknown'),
                'region' => $request->input('region'),
                'period_description' => $request->input('period_description'),
                'new_revision' => $request->boolean('new_revision'),
                'policy' => ['skip_invalid' => $request->boolean('skip_invalid')],
            ]
        );

        $preview = $this->manager->preview($result['dataset_id'], rows: 20);

        return response()->json([
            'dataset_id' => $result['dataset_id'],
            'existing' => $result['existing'],
            'status' => DB::table('datasets')->where('id', $result['dataset_id'])->value('status'),
            'preview' => $preview,
        ], $result['existing'] ? 200 : 201);
    }

    /**
     * Предпросмотр первых строк и сопоставления.
     */
    public function preview(string $id): JsonResponse
    {
        try {
            return response()->json($this->manager->preview($id, rows: (int) config('niche.preview_rows')));
        } catch (\Throwable $e) {
            return response()->json(['code' => 'PREVIEW_FAILED', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Сопоставление колонок и замеров до обработки (ТЗ §9).
     */
    public function updateMapping(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'policy' => ['nullable', 'array'],
            'policy.authoritative_current' => ['nullable', 'in:auto,plain,suffixed'],
            'policy.skip_invalid' => ['nullable', 'boolean'],
            'coverage_type' => ['nullable', 'in:unknown,full_top,sample,filtered'],
        ]);

        $map = $this->manager->setMapping($id, $data['mapping']);
        if (! empty($data['policy'])) {
            $this->manager->setPolicy($id, $data['policy']);
        }
        if (! empty($data['coverage_type'])) {
            DB::table('datasets')->where('id', $id)->update(['coverage_type' => $data['coverage_type']]);
        }

        return response()->json(['mapping' => $map->toArray(), 'valid' => $map->isValid()]);
    }

    /**
     * Поставить импорт в очередь.
     */
    public function import(string $id): JsonResponse
    {
        try {
            $jobId = $this->manager->queueImport($id);
        } catch (\App\Domain\Datasets\ImportAbortException|\RuntimeException $e) {
            return response()->json(['code' => strtok($e->getMessage(), ':'), 'message' => $e->getMessage()], 422);
        }

        return response()->json(['import_job_id' => $jobId, 'status' => 'queued'], 202);
    }

    /**
     * Активация/архивирование — PATCH /datasets/{id} (ТЗ §9).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'is_archived' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:200'],
            'coverage_type' => ['nullable', 'in:unknown,full_top,sample,filtered'],
            'region' => ['nullable', 'string', 'max:100'],
            'period_description' => ['nullable', 'string', 'max:300'],
        ]);

        if (! empty($data['is_active'])) {
            try {
                $this->manager->activate($id);
            } catch (\RuntimeException $e) {
                return response()->json(['code' => 'DATASET_NOT_READY', 'message' => $e->getMessage()], 422);
            }
            unset($data['is_active']);
        }
        if ($data !== []) {
            DB::table('datasets')->where('id', $id)->update($data + ['updated_at' => now()]);
        }

        return $this->show($id);
    }

    /**
     * Удаление отчёта. Без зависимостей — удаляется; при ссылках из ниш/гипотез
     * — 409 со списком сущностей. `?detach=1` — отвязать зависимости и удалить
     * (снимки версий ниш и доказательств сохраняются как история, ТЗ §8).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $result = $this->manager->delete($id, (bool) $request->query('detach'));
        } catch (\RuntimeException $e) {
            return response()->json(['code' => 'DATASET_NOT_FOUND', 'message' => $e->getMessage()], 404);
        }

        if (! $result['deleted']) {
            return response()->json([
                'code' => 'DATASET_HAS_DEPENDENCIES',
                'message' => 'Удаление заблокировано: на отчёт ссылаются пользовательские сущности. '
                    .'Удалите их или повторите удаление с отвязкой (?detach=1): ссылки будут сняты, '
                    .'сохранённые снимки версий ниш и доказательств гипотез останутся как история.',
                'dependencies' => $result['dependencies'],
                'items' => $result['items'],
            ], 409);
        }

        return response()->json(['deleted' => true]);
    }
}
