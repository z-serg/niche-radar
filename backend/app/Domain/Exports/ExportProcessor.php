<?php

namespace App\Domain\Exports;

use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Фоновые экспорты (ТЗ §11): CSV выборки и состава ниши, Markdown
 * карточки гипотезы. CSV по умолчанию UTF-8 BOM, разделитель `;`,
 * корректное экранирование кавычек; режим «для таблиц» защищает
 * текстовые ячейки от формул, не меняя оригинал в БД.
 */
class ExportProcessor
{
    public function __construct(private readonly KeywordSearchService $search) {}

    public function process(string $exportJobId): void
    {
        $job = DB::table('export_jobs')->where('id', $exportJobId)->first();
        if ($job === null) {
            throw new RuntimeException("Экспорт {$exportJobId} не найден.");
        }
        DB::table('export_jobs')->where('id', $exportJobId)->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $params = json_decode((string) $job->params, true);
            $relPath = 'exports/'.$exportJobId.($job->kind === 'hypothesis_md' ? '.md' : '.csv');
            $absPath = storage_path('app/'.$relPath);
            @mkdir(dirname($absPath), 0775, true);

            $rowCount = match ($job->kind) {
                'search_csv' => $this->exportSearchCsv($absPath, $params),
                'niche_csv' => $this->exportNicheCsv($absPath, $params),
                'hypothesis_md' => $this->exportHypothesisMd($absPath, $params),
                default => throw new RuntimeException('UNKNOWN_EXPORT_KIND: '.$job->kind),
            };

            DB::table('export_jobs')->where('id', $exportJobId)->update([
                'status' => 'done',
                'result_path' => $relPath,
                'size_bytes' => filesize($absPath) ?: 0,
                'row_count' => $rowCount,
                'expires_at' => now()->addDays((int) config('niche.export_retention_days', 7)),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('export_jobs')->where('id', $exportJobId)->update([
                'status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public static function queue(string $kind, array $params): string
    {
        $id = (string) Str::uuid();
        DB::table('export_jobs')->insert([
            'id' => $id,
            'kind' => $kind,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Jobs\ExportJob::dispatch($id)->onConnection('default')->onQueue('default');

        return $id;
    }

    // ====================================================================

    private function exportSearchCsv(string $absPath, array $params): int
    {
        $payload = new FilterPayload($params['filters'] ?? []);
        $tablesSafe = ($params['format'] ?? 'tables') === 'tables';
        $datasetId = (string) $params['dataset_id'];

        $runService = app(\App\Domain\Metrics\MetricRunService::class);
        $effectiveRunId = $params['metric_run_id'] ?? $runService->defaultRunId($datasetId);
        $run = DB::table('metric_runs')->where('id', $effectiveRunId)->first();
        $algorithm = $run->algorithm_version ?? 'metrics_v1';

        $handle = fopen($absPath, 'wb');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM по умолчанию (ТЗ §11)

        fputcsv($handle, [
            'Фраза', 'ID фразы', 'Точная текущая', 'Широкая текущая', 'Позиция', 'Предыдущая позиция',
            'Первая точная', 'Прирост (границы)', 'Прирост %', 'Сглаженный прирост A', 'Сглаженный рост G',
            'Устойчивость C', 'Сохранение пика P', 'Признак задачи T', 'Категории задачи', 'Приоритет',
            'Флаги', 'Причины NULL', 'ID отчёта', 'ID расчёта', 'Версия алгоритма',
        ], ';', '"', '\\', "\n");

        $written = 0;
        $this->search->streamMatches($datasetId, $effectiveRunId, $payload, function ($rows) use ($handle, $tablesSafe, $datasetId, $effectiveRunId, $algorithm, &$written) {
            foreach ($rows as $r) {
                $m = $r['metrics'];
                $nullReasons = $m['null_reasons'] instanceof \stdClass ? [] : ($m['null_reasons'] ?? []);
                fputcsv($handle, [
                    $this->cell($r['phrase'], $tablesSafe),
                    $r['keyword_id'],
                    $this->num($r['exact_current']),
                    $this->num($r['broad_current']),
                    $this->num($r['rank_current']),
                    $this->num($r['rank_previous']),
                    $this->num($m['exact_first']),
                    $this->num($m['exact_delta']),
                    $this->num($m['growth_pct']),
                    $this->num($m['smoothed_delta']),
                    $this->num($m['smoothed_growth']),
                    $this->num($m['consistency']),
                    $this->num($m['peak_retention']),
                    $this->num($m['task_score']),
                    implode(', ', $m['task_categories'] ?? []),
                    $this->num($m['priority']),
                    implode(', ', $r['flags']),
                    json_encode($nullReasons, JSON_UNESCAPED_UNICODE),
                    $datasetId,
                    $effectiveRunId,
                    $algorithm,
                ], ';', '"', '\\', "\n");
                $written++;
            }
        });

        fclose($handle);

        return $written;
    }

    private function exportNicheCsv(string $absPath, array $params): int
    {
        $version = DB::table('niche_versions')->where('id', $params['niche_version_id'] ?? '')->first();
        if ($version === null) {
            throw new RuntimeException('NICHE_VERSION_NOT_FOUND');
        }
        $niche = DB::table('niches')->where('id', $version->niche_id)->first();

        $handle = fopen($absPath, 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        $tablesSafe = ($params['format'] ?? 'tables') === 'tables';

        fputcsv($handle, ['Ниша', $niche->name, 'Версия состава', $version->version, 'Создана', $version->created_at], ';', '"', '\\', "\n");
        $aggregates = json_decode((string) $version->aggregates, true) ?: [];
        fputcsv($handle, [$aggregates['exact_sum_label'] ?? ''], ';', '"', '\\', "\n");
        fputcsv($handle, ['Динамика (суммы точных по замерам)', json_encode($aggregates['dynamics'] ?? [], JSON_UNESCAPED_UNICODE)], ';', '"', '\\', "\n");
        fputcsv($handle, [], ';', '"', '\\', "\n");

        fputcsv($handle, [
            'Фраза', 'ID фразы', 'Есть наблюдение', 'Точная текущая', 'Сглаженный прирост A',
            'Сглаженный рост G', 'Устойчивость C', 'Приоритет', 'ID версии расчёта',
        ], ';', '"', '\\', "\n");

        $runId = $version->metric_run_id;
        $written = 0;
        foreach (DB::table('niche_members as nm')
            ->leftJoin('keyword_metrics as km', function ($join) use ($runId) {
                $join->on('km.keyword_id', '=', 'nm.keyword_id')->where('km.metric_run_id', '=', $runId);
            })
            ->join('keywords as k', 'k.id', '=', 'nm.keyword_id')
            ->where('nm.niche_version_id', $version->id)
            ->orderByDesc('km.exact_current')
            ->orderBy('nm.keyword_id')
            ->get(['k.phrase_original', 'nm.keyword_id', 'nm.has_observation', 'km.exact_current', 'km.smoothed_delta', 'km.smoothed_growth', 'km.consistency', 'km.priority']) as $row) {
            fputcsv($handle, [
                $this->cell($row->phrase_original, $tablesSafe),
                $row->keyword_id,
                $row->has_observation ? 'да' : 'нет (нет наблюдения в отчёте)',
                $this->num($row->exact_current),
                $this->num($row->smoothed_delta),
                $this->num($row->smoothed_growth),
                $this->num($row->consistency),
                $this->num($row->priority),
                $runId,
            ], ';', '"', '\\', "\n");
            $written++;
        }
        fclose($handle);

        return $written;
    }

    private function exportHypothesisMd(string $absPath, array $params): int
    {
        $h = DB::table('hypotheses')->where('id', $params['hypothesis_id'] ?? '')->first();
        if ($h === null) {
            throw new RuntimeException('HYPOTHESIS_NOT_FOUND');
        }
        $niche = $h->niche_id ? DB::table('niches')->where('id', $h->niche_id)->first() : null;
        $evidence = DB::table('hypothesis_evidence as e')
            ->join('keywords as k', 'k.id', '=', 'e.keyword_id')
            ->where('e.hypothesis_id', $h->id)
            ->get(['k.phrase_original', 'e.snapshot', 'e.dataset_id', 'e.metric_run_id']);

        $lines = [
            '# Гипотеза: '.$h->title,
            '',
            '- Статус: '.$h->status,
            '- Ниша: '.($niche->name ?? '—'),
            '- Экспорт: '.now()->toDateTimeString(),
            '',
            '## Целевая аудитория', $h->audience ?: '—', '',
            '## Задача', $h->problem ?: '—', '',
            '## Существующий способ решения', $h->current_solution ?: '—', '',
            '## Идея онлайн-сервиса', $h->product_idea ?: '—', '',
            '## Минимальная полезная функция', $h->mvp ?: '—', '',
            '## Монетизация (гипотеза владельца, не данные CSV)', $h->monetization ?: '—', '',
            '## Следующий эксперимент', $h->next_experiment ?: '—', '',
            '## Критерий результата', $h->success_criterion ?: '—', '',
            '## Заметки', $h->notes ?: '—', '',
        ];

        if ($h->status_reason) {
            $lines[] = '## Причина статуса';
            $lines[] = $h->status_reason;
            $lines[] = '';
        }

        $lines[] = '## Подтверждающие фразы';
        $lines[] = '';
        $lines[] = '| Фраза | Точная текущая | Прирост A | Рост G | Приоритет |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($evidence as $e) {
            $snap = json_decode((string) $e->snapshot, true) ?: [];
            $m = $snap['metrics'] ?? [];
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                str_replace('|', '\\|', $e->phrase_original),
                $m['exact_current'] ?? '—',
                isset($m['smoothed_delta']) ? round((float) $m['smoothed_delta'], 1) : '—',
                isset($m['smoothed_growth']) ? round((float) $m['smoothed_growth'], 3) : '—',
                $m['priority'] ?? '—'
            );
        }
        $lines[] = '';
        $lines[] = '_Параметры выборки: отчёт '.($h->dataset_id ?? '—').'; значения сохранены на момент добавления доказательств и не пересчитываются автоматически._';

        file_put_contents($absPath, implode("\n", $lines));

        return count($evidence);
    }

    // ====================================================================

    private function cell(?string $value, bool $tablesSafe): string
    {
        $value = (string) $value;
        if ($tablesSafe && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function num(null|int|float $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
