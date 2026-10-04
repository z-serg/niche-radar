<?php

namespace App\Domain\Datasets;

use App\Jobs\ImportDatasetJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Жизненный цикл отчётов: приём файла с потоковым SHA-256 (одинаковый
 * хеш возвращает существующий отчёт, ТЗ §6.1 п.2), предпросмотр,
 * сопоставление колонок, постановка импорта, активация и удаление
 * с проверкой зависимостей.
 */
class DatasetManager
{
    public function __construct(private readonly BukvarixCsvAdapter $adapter) {}

    /**
     * @return array{dataset_id: string, existing: bool, source_file_id: string}
     */
    public function ingestFile(
        string $sourcePath,
        string $originalName,
        array $meta = [],
    ): array {
        $hash = hash_file('sha256', $sourcePath);
        if ($hash === false) {
            throw new RuntimeException('Не удалось вычислить SHA-256 файла.');
        }
        $size = filesize($sourcePath);

        $existingFile = DB::table('source_files')->where('sha256', $hash)->first();

        if ($existingFile !== null) {
            $existingDataset = DB::table('datasets')
                ->where('source_file_id', $existingFile->id)
                ->orderByDesc('revision')
                ->first();

            if ($existingDataset !== null && empty($meta['new_revision'])) {
                // Одинаковый файл не дублирует данные (ТЗ §14.2).
                return ['dataset_id' => $existingDataset->id, 'existing' => true, 'source_file_id' => $existingFile->id];
            }
        }

        DB::beginTransaction();
        try {
            if ($existingFile === null) {
                $relPath = 'originals/'.$hash.'.csv';
                $dest = storage_path('app/'.$relPath);
                if (! is_file($dest)) {
                    $streamIn = fopen($sourcePath, 'rb');
                    $streamOut = fopen($dest, 'wb');
                    stream_copy_to_stream($streamIn, $streamOut);
                    fclose($streamIn);
                    fclose($streamOut);
                }
                $sourceFileId = (string) Str::uuid();
                DB::table('source_files')->insert([
                    'id' => $sourceFileId,
                    'original_name' => $originalName,
                    'sha256' => $hash,
                    'size_bytes' => $size,
                    'path' => $relPath,
                    'uploaded_at' => now(),
                ]);
            } else {
                $sourceFileId = $existingFile->id;
            }

            $revision = (int) DB::table('datasets')->where('source_file_id', $sourceFileId)->max('revision') + 1;
            $firstDataset = DB::table('datasets')->where('source_file_id', $sourceFileId)->orderBy('revision')->value('id');

            $datasetId = (string) Str::uuid();
            DB::table('datasets')->insert([
                'id' => $datasetId,
                'source_file_id' => $sourceFileId,
                'revision' => $revision,
                'original_dataset_id' => $revision > 1 ? $firstDataset : null,
                'title' => $meta['title'] ?? pathinfo($originalName, PATHINFO_FILENAME),
                'source' => BukvarixCsvAdapter::SOURCE,
                'region' => $meta['region'] ?? null,
                'frequency_definition' => $meta['frequency_definition'] ?? 'wordstat_world',
                'period_description' => $meta['period_description'] ?? null,
                'coverage_type' => in_array($meta['coverage_type'] ?? 'unknown', ['unknown', 'full_top', 'sample', 'filtered'], true)
                    ? ($meta['coverage_type'] ?? 'unknown') : 'unknown',
                'status' => 'uploaded',
                'params' => json_encode($meta['params'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                'policy' => json_encode($meta['policy'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                'quality_flags' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Автосопоставление при приёме.
            $this->autoMap($datasetId);

            DB::commit();

            return ['dataset_id' => $datasetId, 'existing' => false, 'source_file_id' => $sourceFileId];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Распознать заголовки и сохранить сопоставление, если оно полно.
     */
    public function autoMap(string $datasetId): ?HeaderMap
    {
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        $sourceFile = DB::table('source_files')->where('id', $dataset->source_file_id)->first();

        try {
            $map = $this->adapter->mapFile(storage_path('app/'.$sourceFile->path));
        } catch (\Throwable) {
            return null;
        }

        DB::table('datasets')->where('id', $datasetId)->update([
            'mapping' => json_encode($map->toArray(), JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return $map;
    }

    /**
     * Предпросмотр: первые строки, разделитель, заголовки, сопоставление.
     */
    public function preview(string $datasetId, int $rows = 100): array
    {
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        $sourceFile = DB::table('source_files')->where('id', $dataset->source_file_id)->first();
        $path = storage_path('app/'.$sourceFile->path);

        $streamer = new \App\Support\CsvStreamer($path);
        $delimiter = $streamer->detectDelimiter();
        $headers = $streamer->header();
        $map = $this->adapter->map($headers, $delimiter);

        $preview = [];
        foreach ($streamer->records() as $record) {
            $preview[] = $record['fields'];
            if (count($preview) >= $rows) {
                break;
            }
        }

        return [
            'delimiter' => $delimiter,
            'headers' => $headers,
            'column_count' => count($headers),
            'mapping' => $map->toArray(),
            'mapping_valid' => $map->isValid(),
            'rows' => $preview,
            'size_bytes' => (int) $sourceFile->size_bytes,
        ];
    }

    public function setMapping(string $datasetId, array $mapping): HeaderMap
    {
        $map = HeaderMap::fromArray($mapping);
        DB::table('datasets')->where('id', $datasetId)->update([
            'mapping' => json_encode($map->toArray(), JSON_UNESCAPED_UNICODE),
            'status' => in_array(DB::table('datasets')->where('id', $datasetId)->value('status'), ['needs_mapping', 'uploaded']) ? 'uploaded' : DB::raw('status'),
            'updated_at' => now(),
        ]);

        return $map;
    }

    public function setPolicy(string $datasetId, array $policy): void
    {
        DB::table('datasets')->where('id', $datasetId)->update([
            'policy' => json_encode($policy, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    /**
     * Поставить импорт в очередь (один тяжёлый импорт одновременно —
     * гарантируется выделенной очередью с concurrency=1).
     *
     * @return string import_job_id
     */
    public function queueImport(string $datasetId): string
    {
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        if ($dataset === null) {
            throw new RuntimeException('DATASET_NOT_FOUND');
        }
        if (! in_array($dataset->status, ['uploaded', 'needs_mapping', 'failed', 'cancelled', 'ready'])) {
            throw new RuntimeException('DATASET_NOT_IMPORTABLE: статус '.$dataset->status);
        }

        $jobId = (string) Str::uuid();
        DB::table('import_jobs')->insert([
            'id' => $jobId,
            'dataset_id' => $datasetId,
            'stage' => 'queued',
            'status' => 'queued',
            'policy' => $dataset->policy,
            'heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('datasets')->where('id', $datasetId)->update(['status' => 'queued', 'updated_at' => now()]);

        ImportDatasetJob::dispatch($datasetId, $jobId)->onConnection('import')->onQueue('import');

        return $jobId;
    }

    public function cancelImport(string $importJobId): void
    {
        $updated = DB::table('import_jobs')
            ->where('id', $importJobId)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelling']);

        if ($updated === 0) {
            $job = DB::table('import_jobs')->where('id', $importJobId)->first();
            if ($job === null) {
                throw new RuntimeException('IMPORT_NOT_FOUND');
            }
        }
    }

    public function retryImport(string $importJobId): string
    {
        $job = DB::table('import_jobs')->where('id', $importJobId)->first();
        if ($job === null) {
            throw new RuntimeException('IMPORT_NOT_FOUND');
        }
        if (! in_array($job->status, ['failed', 'cancelled', 'done'])) {
            throw new RuntimeException('IMPORT_NOT_RETRYABLE: статус '.$job->status);
        }

        DB::table('import_jobs')->where('id', $importJobId)->update([
            'status' => 'queued',
            'stage' => 'queued',
            'error' => null,
            'attempts' => $job->attempts + 1,
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);

        ImportDatasetJob::dispatch($job->dataset_id, $importJobId)->onConnection('import')->onQueue('import');

        return $importJobId;
    }

    /**
     * Активация готового отчёта (ТЗ §5.1: статус нового отчёта не меняет
     * активный до успешной публикации и выбора пользователя).
     */
    public function activate(string $datasetId): void
    {
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        if ($dataset === null || $dataset->status !== 'ready') {
            throw new RuntimeException('DATASET_NOT_READY');
        }
        DB::transaction(function () use ($datasetId) {
            DB::table('datasets')->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('datasets')->where('id', $datasetId)->update(['is_active' => true, 'updated_at' => now()]);
        });
    }

    /**
     * Зависимости отчёта: какие пользовательские сущности на него ссылаются
     * (ТЗ §8: показывать и блокировать физическое удаление до удаления или
     * переноса). Возвращает человекочитаемый список для UI/API.
     *
     * @return array<int, array{kind: string, id: string, name: string, detail: string}>
     */
    public function listDependencies(string $datasetId): array
    {
        $items = [];

        $niches = DB::table('niche_versions as nv')
            ->join('niches as n', 'n.id', '=', 'nv.niche_id')
            ->where('nv.dataset_id', $datasetId)
            ->selectRaw('n.id, n.name, count(*) as versions, max(nv.version) as last_version')
            ->groupBy('n.id', 'n.name')
            ->orderBy('n.name')
            ->get();
        foreach ($niches as $n) {
            $items[] = [
                'kind' => 'niche',
                'id' => $n->id,
                'name' => $n->name,
                'detail' => "оценено на этом отчёте: версий {$n->versions}, последняя {$n->last_version}",
            ];
        }

        $hypotheses = DB::table('hypotheses')->where('dataset_id', $datasetId)
            ->orderBy('title')->get(['id', 'title']);
        foreach ($hypotheses as $h) {
            $items[] = [
                'kind' => 'hypothesis',
                'id' => $h->id,
                'name' => $h->title,
                'detail' => 'выбранный отчёт гипотезы',
            ];
        }

        $evidence = DB::table('hypothesis_evidence as e')
            ->join('keywords as k', 'k.id', '=', 'e.keyword_id')
            ->where('e.dataset_id', $datasetId)
            ->count();
        if ($evidence > 0) {
            $items[] = [
                'kind' => 'evidence',
                'id' => '',
                'name' => "Доказательства гипотез: {$evidence} фраз",
                'detail' => 'значения сохранены снимками и останутся в карточках гипотез',
            ];
        }

        return $items;
    }

    /**
     * Удаление отчёта (ТЗ §8).
     *
     * $detach=true — «перенос» вместо блокировки: ссылки гипотез/доказательств
     * и версий ниш отвязываются (снимки версий и доказательств сохраняются
     * как история), после чего отчёт удаляется физически.
     */
    public function delete(string $datasetId, bool $detach = false): array
    {
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        if ($dataset === null) {
            throw new RuntimeException('DATASET_NOT_FOUND');
        }

        if (! $detach) {
            $items = $this->listDependencies($datasetId);
            if ($items !== []) {
                $tables = array_values(array_unique(array_map(
                    fn ($i) => $i['kind'] === 'evidence' ? 'hypotheses' : ($i['kind'] === 'niche' ? 'niches' : 'hypotheses'),
                    $items,
                )));

                return ['deleted' => false, 'dependencies' => $tables, 'items' => $items];
            }
        }

        DB::transaction(function () use ($datasetId, $dataset) {
            $runIds = DB::table('metric_runs')->where('dataset_id', $datasetId)->pluck('id');
            if ($runIds->isNotEmpty()) {
                DB::table('keyword_metrics')->whereIn('metric_run_id', $runIds)->delete();
                DB::table('candidate_groups')->whereIn('metric_run_id', $runIds)->delete();
                DB::table('metric_runs')->whereIn('id', $runIds)->delete();
            }
            // Ссылки пользовательских сущностей гасятся FK (ON DELETE SET NULL):
            // hypotheses.dataset_id, hypothesis_evidence.dataset_id,
            // niche_versions.dataset_id/metric_run_id — снимки сохраняются.
            DB::table('niche_versions')->where('dataset_id', $datasetId)->update(['dataset_id' => null]);
            DB::statement('DELETE FROM observations WHERE scan_id IN (SELECT id FROM dataset_scans WHERE dataset_id = ?)', [$datasetId]);
            DB::table('dataset_scans')->where('dataset_id', $datasetId)->delete();
            DB::table('dataset_keywords')->where('dataset_id', $datasetId)->delete();
            DB::table('import_jobs')->where('dataset_id', $datasetId)->delete();

            // Оригинал удаляется, только если других ревизий нет.
            $otherRevisions = DB::table('datasets')
                ->where('source_file_id', $dataset->source_file_id)
                ->where('id', '!=', $datasetId)
                ->exists();
            DB::table('datasets')->where('id', $datasetId)->delete();
            if (! $otherRevisions) {
                $file = DB::table('source_files')->where('id', $dataset->source_file_id)->first();
                if ($file !== null) {
                    @unlink(storage_path('app/'.$file->path));
                    DB::table('source_files')->where('id', $file->id)->delete();
                }
            }
        });

        return ['deleted' => true, 'dependencies' => [], 'items' => []];
    }

    public static function activeDatasetId(): ?string
    {
        return DB::table('datasets')->where('is_active', true)->orderByDesc('updated_at')->value('id');
    }
}
