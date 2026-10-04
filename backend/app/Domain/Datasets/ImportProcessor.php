<?php

namespace App\Domain\Datasets;

use App\Domain\Metrics\MetricRunService;
use App\Domain\Rules\RuleMatcher;
use App\Domain\Rules\RuleSetRegistry;
use App\Support\CopyFormat;
use App\Support\CsvStreamer;
use App\Support\Tokenizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Конвейер импорта отчёта (ТЗ §6): потоковая валидация и разгрузка в
 * staging-таблицы через COPY, set-based перенос в keywords /
 * dataset_keywords / observations / keyword_tokens, расчёт метрик и
 * предложений групп. Этапы идемпотентны, отмена проверяется между
 * пакетами; память ограничена размером пакета.
 */
class ImportProcessor
{
    public function __construct(
        private readonly MetricRunService $metricRuns,
        private readonly RuleMatcher $ruleMatcher,
    ) {}

    /**
     * @return array{status: string, dataset_status: string, summary: array}
     */
    public function process(string $datasetId, ?string $importJobId = null): array
    {
        $dataset = DB::table('datasets')->where('id', $datasetId)->first();
        if ($dataset === null) {
            throw new RuntimeException("Отчёт {$datasetId} не найден.");
        }
        $sourceFile = DB::table('source_files')->where('id', $dataset->source_file_id)->first();
        $path = storage_path('app/'.$sourceFile->path);

        $policy = (array) json_decode($dataset->policy ?? '{}', true);
        $skipInvalid = (bool) ($policy['skip_invalid'] ?? false);
        $authoritative = $policy['authoritative_current'] ?? 'auto'; // auto|plain|suffixed

        $this->setJob($importJobId, ['status' => 'running', 'stage' => 'validating', 'heartbeat_at' => now(), 'started_at' => now(), 'error' => null]);
        $this->setDataset($datasetId, ['status' => 'validating']);

        $ruleSet = RuleSetRegistry::activeTaskSignals();
        $rulesVersion = $ruleSet['version'];

        // --- Сопоставление колонок ---
        $mapping = $dataset->mapping ? HeaderMap::fromArray((array) json_decode($dataset->mapping, true)) : null;
        if ($mapping === null || ! $mapping->isValid()) {
            $this->setDataset($datasetId, ['status' => 'needs_mapping']);
            $this->setJob($importJobId, [
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'NEEDS_MAPPING: сопоставление колонок отсутствует или неполно.',
            ]);

            return ['status' => 'needs_mapping', 'dataset_status' => 'needs_mapping', 'summary' => []];
        }

        // --- Замеры (идемпотентно) ---
        $scanIds = $this->ensureScans($datasetId, $mapping);
        $nScans = count($mapping->scans);

        // --- Staging ---
        $tag = str_replace('-', '', substr(Str::uuid()->toString(), 0, 12));
        $stgRows = "stg_rows_{$tag}";
        $stgTokens = "stg_tokens_{$tag}";
        $stgFinal = "stg_final_{$tag}";
        $stagingDir = storage_path("app/staging/{$datasetId}");
        @mkdir($stagingDir, 0775, true);

        try {
            $this->dropTables([$stgRows, $stgTokens, $stgFinal]);
            DB::statement("CREATE UNLOGGED TABLE {$stgRows} ({$this->stagingColumns($nScans)})");
            DB::statement("CREATE UNLOGGED TABLE {$stgTokens} (row_no bigint, token text)");

            $rowsWriter = new BatchTsvWriter($stagingDir.'/rows.tsv', $stgRows);
            $tokensWriter = new BatchTsvWriter($stagingDir.'/tokens.tsv', $stgTokens);

            // --- Потоковая валидация + разгрузка ---
            $this->setJob($importJobId, ['stage' => 'importing']);
            $this->setDataset($datasetId, ['status' => 'importing']);
            $stats = $this->streamAndStage(
                $datasetId, $importJobId, $path, $mapping, $rowsWriter, $tokensWriter,
                $skipInvalid, $authoritative, $ruleSet, $rulesVersion
            );

            // --- Конфликтующие дубликаты (§6.1 п.8) ---
            $conflicts = $this->findConflictingDuplicates($stgRows, $nScans);
            if ($conflicts > 0 && ! ($policy['drop_conflicting_duplicates'] ?? false)) {
                $this->fail($importJobId, $datasetId,
                    "CONFLICTING_DUPLICATES: {$conflicts} фраз с одинаковым написанием, но разными значениями. ".
                    'Исправьте файл или разрешите исключение конфликтующих дубликатов.');
                throw new ImportAbortException();
            }

            // --- Set-based загрузка ---
            $this->stageProgress($importJobId, 'loading', 62);
            $this->loadKeywords($stgRows, $stgFinal, $rulesVersion);
            $loadStats = $this->loadDatasetData($datasetId, $stgRows, $stgFinal, $stgTokens, $scanIds, $nScans, $rulesVersion);
            $this->checkCancel($importJobId);

            // --- Расчёт метрик и групп ---
            $this->setJob($importJobId, ['stage' => 'calculating']);
            $this->setDataset($datasetId, ['status' => 'calculating']);
            $run = $this->metricRuns->buildDefaultRun($datasetId, $importJobId);

            $flags = $stats['quality_flags'];
            $summary = array_merge($stats['summary'], $loadStats, [
                'metric_run_id' => $run['id'],
                'preset_counts' => $run['preset_counts'],
                'groups' => $run['groups'],
                'scans' => $nScans,
            ]);

            $this->setDataset($datasetId, [
                'status' => 'ready',
                'row_count' => $loadStats['row_count'],
                'scan_count' => $nScans,
                'quality_flags' => json_encode(array_values(array_unique($flags)), JSON_UNESCAPED_UNICODE),
            ]);
            $this->setJob($importJobId, [
                'status' => 'done',
                'stage' => 'done',
                'finished_at' => now(),
                'progress' => json_encode(['stage' => 'done', 'pct' => 100] + $summary, JSON_UNESCAPED_UNICODE),
            ]);

            return ['status' => 'done', 'dataset_status' => 'ready', 'summary' => $summary];
        } catch (ImportCancelledException) {
            $this->setDataset($datasetId, ['status' => 'cancelled']);
            $this->setJob($importJobId, ['status' => 'cancelled', 'stage' => 'cancelled', 'finished_at' => now()]);

            return ['status' => 'cancelled', 'dataset_status' => 'cancelled', 'summary' => []];
        } catch (ImportBlockedException $e) {
            // Расхождение текущих колонок: публикация блокируется до
            // выбора владельцем основной колонки (ТЗ §4.2).
            $flags = array_values(array_unique(array_merge(
                (array) json_decode($dataset->quality_flags ?? '[]', true),
                ['current_columns_mismatch']
            )));
            $this->setDataset($datasetId, ['status' => 'needs_mapping', 'quality_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE)]);
            $this->setJob($importJobId, ['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);

            return ['status' => 'blocked', 'dataset_status' => 'needs_mapping', 'summary' => []];
        } catch (ImportAbortException $e) {
            $this->fail($importJobId, $datasetId, $e->getMessage());

            return ['status' => 'failed', 'dataset_status' => 'failed', 'summary' => []];
        } catch (\Throwable $e) {
            $this->fail($importJobId, $datasetId, 'IMPORT_FAILED: '.$e->getMessage());

            return ['status' => 'failed', 'dataset_status' => 'failed', 'summary' => []];
        } finally {
            $rowsWriter?->close();
            $tokensWriter?->close();
            $this->dropTables([$stgRows, $stgTokens, $stgFinal]);
            @unlink($stagingDir.'/rows.tsv');
            @unlink($stagingDir.'/tokens.tsv');
            @rmdir($stagingDir);
        }
    }

    // ====================================================================
    // Потоковая валидация и разгрузка
    // ====================================================================

    private function streamAndStage(
        string $datasetId,
        ?string $importJobId,
        string $path,
        HeaderMap $mapping,
        BatchTsvWriter $rowsWriter,
        BatchTsvWriter $tokensWriter,
        bool $skipInvalid,
        string $authoritative,
        array $ruleSet,
        string $rulesVersion,
    ): array {
        $streamer = new CsvStreamer($path);
        $streamer->detectDelimiter();
        $totalBytes = max(1, $streamer->fileSize());

        $nScans = count($mapping->scans);
        $newestIdx = $nScans - 1;

        // Эффективные колонки замеров. Ненумерованная «текущая» колонка
        // дублирует замер суффикса 1: основную выбирает политика.
        $exactCols = [];
        $broadCols = [];
        foreach ($mapping->scans as $i => $scan) {
            $exactCols[$i] = $scan['exact_col'];
            $broadCols[$i] = $scan['broad_col'];
        }
        $verifyExact = $verifyBroad = null;
        if ($mapping->currentExactCol !== null) {
            if ($exactCols[$newestIdx] !== null) {
                if ($authoritative === 'plain') {
                    $verifyExact = $exactCols[$newestIdx];
                    $exactCols[$newestIdx] = $mapping->currentExactCol;
                } else {
                    $verifyExact = $mapping->currentExactCol;
                }
            } else {
                $exactCols[$newestIdx] = $mapping->currentExactCol;
            }
        }
        if ($mapping->currentBroadCol !== null) {
            if ($broadCols[$newestIdx] !== null) {
                if ($authoritative === 'plain') {
                    $verifyBroad = $broadCols[$newestIdx];
                    $broadCols[$newestIdx] = $mapping->currentBroadCol;
                } else {
                    $verifyBroad = $mapping->currentBroadCol;
                }
            } else {
                $broadCols[$newestIdx] = $mapping->currentBroadCol;
            }
        }

        $rowNo = 0;
        $skipped = 0;
        $mismatches = 0;
        $batchRows = (int) config('niche.import_batch_rows');
        $batchStart = microtime(true);
        $batchesDone = 0;
        $startedAt = microtime(true);
        $expectedCols = $mapping->maxColumnIndex() + 1;

        $first = true;
        foreach ($streamer->records() as $record) {
            // Первая запись — строка заголовков (уже распознана адаптером).
            if ($first) {
                $first = false;
                continue;
            }
            $rowNoInBatch = $rowNo % $batchRows;
            if ($rowNo > 0 && $rowNoInBatch === 0) {
                $rowsWriter->flush();
                $tokensWriter->flush();
                $batchesDone++;

                $elapsed = microtime(true) - $batchStart;
                $batchStart = microtime(true);
                $bytes = $streamer->tell();
                $avg = $rowNo / max(0.001, microtime(true) - $startedAt);
                $progress = [
                    'stage' => 'importing',
                    'pct' => (int) min(59, round($bytes / $totalBytes * 59)),
                    'rows' => $rowNo,
                    'bytes' => $bytes,
                    'total_bytes' => $totalBytes,
                    'rows_per_sec' => (int) $avg,
                ];
                // ETA показываем только после накопления статистики (ТЗ §5.1).
                if ($batchesDone >= 2) {
                    $progress['eta_sec'] = (int) max(0, ($totalBytes - $bytes) / max(1, $totalBytes / (microtime(true) - $startedAt)));
                }
                $this->heartbeat($importJobId, $progress);
                $this->checkCancel($importJobId);
            }

            $fields = $record['fields'];
            $recordNo = $record['record_no'];

            if (count($fields) < $expectedCols) {
                $this->handleRecordError($importJobId, $recordNo, null, 'COLUMN_COUNT',
                    sprintf('Ожидалось ≥%d колонок, получено %d', $expectedCols, count($fields)), $skipInvalid, $skipped);
                continue;
            }

            try {
                $phrase = trim((string) $fields[$mapping->phraseCol]);
                if ($phrase === '') {
                    throw new ImportRecordException('EMPTY_PHRASE', 'Пустая фраза.', $mapping->phraseCol);
                }
                $normalized = \Normalizer::normalize($phrase, \Normalizer::FORM_C);
                if ($normalized === false) {
                    throw new ImportRecordException('INVALID_PHRASE', 'Не удалось нормализовать фразу (NFC).', $mapping->phraseCol);
                }
                $searchText = trim(mb_strtolower(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized, 'UTF-8'));

                $rankCur = $this->parseIntField($fields, $mapping->rankCurrentCol, 'RANK_CURRENT', allowEmpty: false);
                $rankPrev = $this->parseIntField($fields, $mapping->rankPreviousCol, 'RANK_PREVIOUS', allowEmpty: true, default: 0);
                $wcSrc = $mapping->wordCountCol !== null
                    ? $this->parseIntField($fields, $mapping->wordCountCol, 'WORD_COUNT', allowEmpty: true, nullable: true)
                    : null;
                $ccSrc = $mapping->charCountCol !== null
                    ? $this->parseIntField($fields, $mapping->charCountCol, 'CHAR_COUNT', allowEmpty: true, nullable: true)
                    : null;

                $exact = [];
                $broad = [];
                foreach ($exactCols as $i => $col) {
                    $exact[$i] = $this->parseFreq($fields, $col, 'точная', $i);
                }
                foreach ($broadCols as $i => $col) {
                    $broad[$i] = $this->parseFreq($fields, $col, 'широкая', $i);
                }

                // Сверка ненумерованной текущей колонки с замером суффикса 1.
                if ($verifyExact !== null) {
                    $plain = $this->parseFreq($fields, $verifyExact, 'текущая точная', $newestIdx);
                    $same = ($plain ?? null) === ($exact[$newestIdx] ?? null);
                    if (! $same && $authoritative === 'auto') {
                        throw new ImportBlockedException(
                            'CURRENT_COLUMNS_MISMATCH: ненумерованная колонка точной частотности расходится с колонкой суффикса 1 (запись '.$recordNo.'). '.
                            'Публикация заблокирована: выберите основную колонку (authoritative_current = plain|suffixed) и перезапустите импорт.'
                        );
                    }
                    if (! $same) {
                        $mismatches++;
                    }
                }
                if ($verifyBroad !== null) {
                    $plain = $this->parseFreq($fields, $verifyBroad, 'текущая широкая', $newestIdx);
                    $same = ($plain ?? null) === ($broad[$newestIdx] ?? null);
                    if (! $same && $authoritative === 'auto') {
                        throw new ImportBlockedException(
                            'CURRENT_COLUMNS_MISMATCH: ненумерованная колонка широкой частотности расходится с колонкой суффикса 1 (запись '.$recordNo.').'
                        );
                    }
                    if (! $same) {
                        $mismatches++;
                    }
                }

                // Текущая точная частотность обязательна (ТЗ §6.1 п.7).
                if ($exact[$newestIdx] === null) {
                    throw new ImportRecordException('MISSING_EXACT_CURRENT', 'Отсутствует текущая точная частотность.', $exactCols[$newestIdx]);
                }

                $tokens = Tokenizer::searchTokens($searchText);
                $match = $this->ruleMatcher->match($ruleSet, $searchText, $tokens);

                $rowNo++;
                $rowsWriter->writeLine(CopyFormat::line(array_merge(
                    [
                        (string) $rowNo,
                        CopyFormat::field($phrase),        // исходное написание
                        hash('sha256', $normalized),
                        CopyFormat::field($searchText),
                        (string) count($tokens),
                        (string) Tokenizer::charCount($normalized),
                        CopyFormat::intOrNull($wcSrc),
                        CopyFormat::intOrNull($ccSrc),
                        (string) $rankCur,
                        (string) $rankPrev,
                        (string) $match['score'],
                        $match['categories'] === [] ? '\N' : CopyFormat::field(json_encode($match['categories'], JSON_UNESCAPED_UNICODE)),
                        $match['rule_ids'] === [] ? '\N' : CopyFormat::field(json_encode($match['rule_ids'], JSON_UNESCAPED_UNICODE)),
                    ],
                    array_map(fn ($i) => CopyFormat::intOrNull($broadCols[$i] !== null ? ($broad[$i] ?? null) : null), range(0, $nScans - 1)),
                    array_map(fn ($i) => CopyFormat::intOrNull($exactCols[$i] !== null ? ($exact[$i] ?? null) : null), range(0, $nScans - 1)),
                )));
                foreach ($tokens as $token) {
                    $tokensWriter->writeLine($rowNo."\t".CopyFormat::field($token)."\n");
                }
            } catch (ImportRecordException $e) {
                $this->handleRecordError($importJobId, $recordNo, $e->column, $e->errCode, $e->getMessage(), $skipInvalid, $skipped);
                continue;
            }
        }

        $rowsWriter->flush();
        $tokensWriter->flush();

        $flags = [];
        if ($skipped > 0) {
            $flags[] = 'skipped_rows:'.$skipped;
        }
        if ($mismatches > 0) {
            $flags[] = 'current_columns_mismatch:'.$mismatches;
        }
        $elapsedTotal = microtime(true) - $startedAt;

        return [
            'quality_flags' => $flags,
            'summary' => [
                'rows_read' => $rowNo + $skipped,
                'rows_loaded' => $rowNo,
                'rows_skipped' => $skipped,
                'import_seconds' => round($elapsedTotal, 1),
                'rows_per_sec' => (int) ($rowNo / max(0.001, $elapsedTotal)),
                'rules_version' => $rulesVersion,
            ],
        ];
    }

    /**
     * @param-out int $skipped
     */
    private function handleRecordError(?string $importJobId, int $recordNo, ?int $column, string $code, string $message, bool $skipInvalid, int &$skipped): void
    {
        if ($importJobId !== null) {
            DB::table('import_errors')->insert([
                'import_job_id' => $importJobId,
                'record_no' => $recordNo,
                'column_index' => $column,
                'code' => $code,
                'message' => $message,
                'created_at' => now(),
            ]);
        }
        if ($skipInvalid) {
            $skipped++;

            return;
        }
        // Строгий режим по умолчанию (ТЗ §6.1 п.9).
        throw new ImportAbortException("STRICT_MODE_ERROR[{$code}] запись {$recordNo}: {$message}");
    }

    // ====================================================================
    // Set-based загрузка
    // ====================================================================

    private function findConflictingDuplicates(string $stgRows, int $nScans): int
    {
        $valueCols = ['rank_cur', 'rank_prev'];
        for ($i = 1; $i <= $nScans; $i++) {
            $valueCols[] = "b{$i}";
            $valueCols[] = "e{$i}";
        }
        $tuple = '('.implode(',', $valueCols).')';

        return (int) DB::selectOne("
            SELECT count(*) AS n FROM (
                SELECT identity_hash FROM {$stgRows}
                GROUP BY identity_hash
                HAVING count(*) > 1 AND count(DISTINCT {$tuple}) > 1
            ) t
        ")->n;
    }

    private function loadKeywords(string $stgRows, string $stgFinal, string $rulesVersion): void
    {
        // phrase_original — исходное написание первой встречи фразы.
        DB::statement("
            INSERT INTO keywords (phrase_original, identity_hash, search_text, word_count, char_count, task_score, task_categories, task_rule_ids, task_rules_version)
            SELECT DISTINCT ON (identity_hash) phrase, identity_hash, search_text, word_count, char_count, task_score,
                   NULLIF(task_categories,'\N')::jsonb, NULLIF(task_rule_ids,'\N')::jsonb, ?
            FROM {$stgRows}
            ORDER BY identity_hash, row_no
            ON CONFLICT (identity_hash) DO UPDATE SET
                task_score = EXCLUDED.task_score,
                task_categories = EXCLUDED.task_categories,
                task_rule_ids = EXCLUDED.task_rule_ids,
                task_rules_version = EXCLUDED.task_rules_version
            WHERE keywords.task_rules_version IS DISTINCT FROM EXCLUDED.task_rules_version
        ", [$rulesVersion]);

        DB::statement("
            CREATE TABLE {$stgFinal} AS
            SELECT DISTINCT ON (identity_hash) row_no, phrase, identity_hash, search_text, word_count, char_count,
                   wc_src, cc_src, rank_cur, rank_prev, task_score, task_categories, task_rule_ids, {$this->freqColumnList($this->tableScanCount($stgRows))},
                   NULL::bigint AS keyword_id
            FROM {$stgRows}
            ORDER BY identity_hash, row_no
        ");

        DB::statement("
            UPDATE {$stgFinal} f SET keyword_id = k.id
            FROM keywords k WHERE k.identity_hash = f.identity_hash
        ");

        // Проверка строки при совпадении хеша (ТЗ §4.2).
        $collisions = DB::selectOne("
            SELECT count(*) AS n FROM {$stgFinal} f
            JOIN keywords k ON k.identity_hash = f.identity_hash
            WHERE k.phrase_original <> f.phrase
        ")->n;
        if ($collisions > 0) {
            throw new RuntimeException("IDENTITY_HASH_COLLISION: {$collisions} коллизий хеша фраз (практически исключено).");
        }
    }

    private function loadDatasetData(string $datasetId, string $stgRows, string $stgFinal, string $stgTokens, array $scanIds, int $nScans, string $rulesVersion): array
    {
        $rawCount = (int) DB::table($stgRows)->count();

        // Идемпотентность: очистка прежних данных отчёта перед загрузкой.
        DB::statement('DELETE FROM observations WHERE scan_id IN (SELECT id FROM dataset_scans WHERE dataset_id = ?)', [$datasetId]);
        DB::table('dataset_keywords')->where('dataset_id', $datasetId)->delete();
        $oldRuns = DB::table('metric_runs')->where('dataset_id', $datasetId)->pluck('id');
        if ($oldRuns->isNotEmpty()) {
            DB::table('candidate_groups')->whereIn('metric_run_id', $oldRuns)->delete();
            DB::table('keyword_metrics')->whereIn('metric_run_id', $oldRuns)->delete();
            DB::table('niche_versions')->where('dataset_id', $datasetId)->update(['metric_run_id' => null]);
            DB::table('metric_runs')->whereIn('id', $oldRuns)->delete();
        }

        DB::statement("
            INSERT INTO dataset_keywords (dataset_id, keyword_id, source_row, rank_current, rank_previous, word_count_source, char_count_source)
            SELECT ?, keyword_id, row_no, rank_cur, rank_prev, wc_src, cc_src
            FROM {$stgFinal}
        ", [$datasetId]);

        foreach ($scanIds as $ordinal => $scanId) {
            DB::statement("
                INSERT INTO observations (scan_id, keyword_id, exact, broad)
                SELECT ?, keyword_id, e{$ordinal}, b{$ordinal}
                FROM {$stgFinal}
                WHERE e{$ordinal} IS NOT NULL OR b{$ordinal} IS NOT NULL
            ", [$scanId]);
        }

        DB::statement("
            INSERT INTO keyword_tokens (keyword_id, token)
            SELECT DISTINCT f.keyword_id, t.token
            FROM {$stgTokens} t JOIN {$stgFinal} f ON f.row_no = t.row_no
            ON CONFLICT DO NOTHING
        ");

        DB::statement("
            INSERT INTO keyword_labels (keyword_id, label, origin, rule_version, created_at)
            SELECT DISTINCT f.keyword_id, cat, 'rule', ?, now()
            FROM {$stgFinal} f
            CROSS JOIN LATERAL jsonb_array_elements_text(NULLIF(f.task_categories,'\N')::jsonb) AS cat
            ON CONFLICT DO NOTHING
        ", [$rulesVersion]);

        $rowCount = (int) DB::table('dataset_keywords')->where('dataset_id', $datasetId)->count();
        $obsCount = (int) DB::selectOne(
            'SELECT count(*) AS n FROM observations WHERE scan_id IN (SELECT id FROM dataset_scans WHERE dataset_id = ?)',
            [$datasetId]
        )->n;

        return [
            'row_count' => $rowCount,
            'observation_count' => $obsCount,
            'duplicate_rows_collapsed' => max(0, $rawCount - $rowCount),
        ];
    }

    // ====================================================================
    // Служебное
    // ====================================================================

    private function ensureScans(string $datasetId, HeaderMap $mapping): array
    {
        $existing = DB::table('dataset_scans')->where('dataset_id', $datasetId)->get()->keyBy('ordinal');
        $scanIds = [];
        foreach ($mapping->scans as $scan) {
            $ord = $scan['ordinal'];
            if ($existing->has($ord)) {
                $scanIds[$ord] = $existing->get($ord)->id;
                continue;
            }
            $scanIds[$ord] = DB::table('dataset_scans')->insertGetId([
                'dataset_id' => $datasetId,
                'ordinal' => $ord,
                'source_header' => implode(' / ', array_filter($scan['headers'])) ?: 'scan '.$ord,
                'source_suffix' => $scan['suffix'],
                'metadata' => json_encode(['headers' => $scan['headers']], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $scanIds;
    }

    private function stagingColumns(int $nScans): string
    {
        $cols = [
            'row_no bigint', 'phrase text', 'identity_hash text', 'search_text text',
            'word_count int', 'char_count int', 'wc_src int', 'cc_src int',
            'rank_cur int', 'rank_prev int', 'task_score float8',
            'task_categories text', 'task_rule_ids text',
        ];
        // Порядок: сначала все широкие, затем все точные — как в TSV-строке.
        for ($i = 1; $i <= $nScans; $i++) {
            $cols[] = "b{$i} bigint";
        }
        for ($i = 1; $i <= $nScans; $i++) {
            $cols[] = "e{$i} bigint";
        }

        return implode(', ', $cols);
    }

    private function tableScanCount(string $table): int
    {
        $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = ?", [$table]);
        $max = 0;
        foreach ($cols as $c) {
            if (preg_match('/^e(\d+)$/', $c->column_name, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max;
    }

    private function freqColumnList(int $nScans): string
    {
        $cols = [];
        for ($i = 1; $i <= $nScans; $i++) {
            $cols[] = "b{$i}";
        }
        for ($i = 1; $i <= $nScans; $i++) {
            $cols[] = "e{$i}";
        }

        return implode(', ', $cols);
    }

    private function parseIntField(
        array $fields,
        ?int $col,
        string $code,
        bool $allowEmpty = false,
        int $default = 0,
        bool $nullable = false,
    ): null|int {
        if ($col === null) {
            return $nullable ? null : $default;
        }
        $raw = trim((string) ($fields[$col] ?? ''));
        if ($raw === '') {
            if ($allowEmpty) {
                return $nullable ? null : $default;
            }
            throw new ImportRecordException($code, 'Обязательное числовое поле пусто.', $col);
        }
        if (! preg_match('/^\d+$/', $raw)) {
            throw new ImportRecordException($code, "Ожидалось неотрицательное целое, получено «{$raw}».", $col);
        }

        return (int) $raw;
    }

    private function parseFreq(array $fields, ?int $col, string $kind, int $idx): null|int
    {
        if ($col === null) {
            return null;
        }
        $raw = trim((string) ($fields[$col] ?? ''));
        if ($raw === '') {
            return null; // пустая ячейка — пропуск, не ноль (ТЗ §4.1)
        }
        if (! preg_match('/^\d+$/', $raw)) {
            throw new ImportRecordException('INVALID_FREQUENCY', "Некорректная {$kind} частотность «{$raw}» замера ".($idx + 1).'.', $col);
        }

        return (int) $raw;
    }

    private function heartbeat(?string $importJobId, array $progress): void
    {
        if ($importJobId === null) {
            return;
        }
        DB::table('import_jobs')->where('id', $importJobId)->update([
            'heartbeat_at' => now(),
            'progress' => json_encode($progress, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function stageProgress(?string $importJobId, string $stage, int $pct): void
    {
        if ($importJobId === null) {
            return;
        }
        DB::table('import_jobs')->where('id', $importJobId)->update([
            'stage' => $stage,
            'heartbeat_at' => now(),
            'progress' => json_encode(['stage' => $stage, 'pct' => $pct], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function checkCancel(?string $importJobId): void
    {
        if ($importJobId === null) {
            return;
        }
        $status = DB::table('import_jobs')->where('id', $importJobId)->value('status');
        if ($status === 'cancelling') {
            throw new ImportCancelledException();
        }
    }

    private function setJob(?string $importJobId, array $data): void
    {
        if ($importJobId === null) {
            return;
        }
        DB::table('import_jobs')->where('id', $importJobId)->update($data);
    }

    private function setDataset(string $datasetId, array $data): void
    {
        DB::table('datasets')->where('id', $datasetId)->update($data + ['updated_at' => now()]);
    }

    private function fail(?string $importJobId, string $datasetId, string $message): void
    {
        $this->setDataset($datasetId, ['status' => 'failed']);
        $this->setJob($importJobId, ['status' => 'failed', 'finished_at' => now(), 'error' => $message]);
    }

    private function dropTables(array $tables): void
    {
        foreach ($tables as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t}");
        }
    }
}

class ImportRecordException extends \RuntimeException
{
    public function __construct(public readonly string $errCode, string $message, public readonly ?int $column)
    {
        parent::__construct($message);
    }
}

class ImportBlockedException extends \RuntimeException {}

class ImportCancelledException extends \RuntimeException {}

class ImportAbortException extends \RuntimeException {}
