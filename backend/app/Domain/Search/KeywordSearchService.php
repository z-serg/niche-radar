<?php

namespace App\Domain\Search;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Поиск фраз по материализованным метрикам прогона: серверная сортировка
 * с устойчивым вторичным ключом keyword_id и курсорной keyset-пагинацией
 * вместо глубокого OFFSET (ТЗ §5.2, §9).
 */
class KeywordSearchService
{
    /** Разрешённые поля сортировки (валидация по списку, ТЗ §9). */
    public const SORT_FIELDS = [
        'priority' => 'km.priority',
        'exact_current' => 'km.exact_current',
        'broad_current' => 'km.broad_current',
        'exact_delta' => 'km.exact_delta',
        'growth_pct' => 'km.growth_pct',
        'base_avg' => 'km.base_avg',
        'smoothed_delta' => 'km.smoothed_delta',
        'smoothed_growth' => 'km.smoothed_growth',
        'consistency' => 'km.consistency',
        'peak_retention' => 'km.peak_retention',
        'task_score' => 'km.task_score',
        'n_observations' => 'km.n_observations',
        'rank_current' => 'km.rank_current',
        'rank_delta' => 'RANK_DELTA',
        'phrase' => 'k.search_text',
        'keyword_id' => 'km.keyword_id',
    ];

    private const RANGE_COLUMNS = [
        'exact_current' => 'km.exact_current',
        'broad_current' => 'km.broad_current',
        'exact_delta' => 'km.exact_delta',
        'growth_pct' => 'km.growth_pct',
        'base_avg' => 'km.base_avg',
        'smoothed_delta' => 'km.smoothed_delta',
        'smoothed_growth' => 'km.smoothed_growth',
        'consistency' => 'km.consistency',
        'peak_retention' => 'km.peak_retention',
        'task_score' => 'km.task_score',
        'n_observations' => 'km.n_observations',
        'rank_current' => 'km.rank_current',
    ];

    private const RANK_DELTA_EXPR = '(CASE WHEN km.rank_previous > 0 THEN km.rank_previous - km.rank_current END)';

    public function __construct(private readonly \App\Domain\Metrics\MetricRunService $runs) {}

    /**
     * Поиск с курсорной пагинацией.
     *
     * @return array{data: array, next_cursor: ?string, truncated: bool, hard_limit_reached: bool, applied: array}
     */
    public function search(string $datasetId, ?string $metricRunId, FilterPayload $payload): array
    {
        [$runId, $run] = $this->resolveRun($datasetId, $metricRunId);

        $pos = 0;
        if ($payload->cursor !== null) {
            $cur = $this->decodeCursor($payload->cursor);
            $pos = $cur['p'];
        }

        $built = $this->buildQuery($datasetId, $runId, $payload);
        $rows = DB::select($built['sql'], $built['bindings']);

        $hasMore = count($rows) > $payload->limit;
        $rows = array_slice($rows, 0, $payload->limit);

        $hardLimit = (int) config('niche.search_hard_limit');
        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[count($rows) - 1];
            $nextCursor = $this->encodeCursor($last, $payload->sort, $pos + count($rows));
        }

        $history = $this->historyFor($datasetId, array_map(fn ($r) => (int) $r->keyword_id, $rows));

        $data = [];
        foreach ($rows as $r) {
            $data[] = $this->formatRow($r, $history[(int) $r->keyword_id] ?? [], $run);
        }

        return [
            'data' => $data,
            'next_cursor' => $nextCursor,
            'truncated' => $hasMore,
            'hard_limit_reached' => $pos + count($rows) >= $hardLimit && $hasMore,
            'applied' => [
                'filters' => json_decode($payload->canonical(), true),
                'preset' => $payload->preset ? Presets::describe($payload->preset) : null,
                'sort' => $payload->sort,
                'limit' => $payload->limit,
            ],
        ];
    }

    /**
     * Общее число совпадений (отдельно, может быть тяжёлым; ТЗ §5.2).
     */
    public function count(string $datasetId, ?string $metricRunId, FilterPayload $payload): int
    {
        [$runId] = $this->resolveRun($datasetId, $metricRunId);
        $built = $this->buildQuery($datasetId, $runId, $payload, countOnly: true);

        return (int) DB::selectOne($built['sql'], $built['bindings'])->n;
    }

    /**
     * Потоковая выдача всех совпадений чанками — экспорт и ниши-правила.
     *
     * @param  callable(array<int, array>): void  $callback каждый элемент — строка из search()['data']
     */
    public function streamMatches(string $datasetId, string $metricRunId, FilterPayload $payload, callable $callback, int $chunk = 5000): int
    {
        $base = json_decode($payload->canonical(), true);
        $base['limit'] = $chunk;
        $cursor = null;
        $total = 0;
        do {
            $effective = new FilterPayload($base + ['cursor' => $cursor]);
            $result = $this->search($datasetId, $metricRunId, $effective);
            if ($result['data'] !== []) {
                $callback($result['data']);
                $total += count($result['data']);
            }
            $cursor = $result['next_cursor'];
        } while ($cursor !== null && $total < (int) config('niche.search_hard_limit'));

        return $total;
    }

    // ====================================================================
    // Построение запроса
    // ====================================================================

    private function buildQuery(string $datasetId, string $runId, FilterPayload $payload, bool $countOnly = false): array
    {
        $whereBindings = [$runId];
        $wheres = ['km.metric_run_id = ?'];

        if ($payload->include !== null) {
            $wheres[] = $this->termCondition($payload->include, false, $whereBindings);
        }
        if ($payload->exclude !== null) {
            $wheres[] = $this->termCondition($payload->exclude, true, $whereBindings);
        }

        foreach ($payload->ranges as $field => $range) {
            $col = self::RANGE_COLUMNS[$field] ?? ($field === 'word_count' ? 'k.word_count' : null);
            if ($col === null) {
                continue;
            }
            if (isset($range['min'])) {
                $wheres[] = "{$col} >= ?";
                $whereBindings[] = $range['min'];
            }
            if (isset($range['max'])) {
                $wheres[] = "{$col} <= ?";
                $whereBindings[] = $range['max'];
            }
        }

        foreach ($payload->flags as $flag => $value) {
            $expr = match ($flag) {
                'is_new' => 'km.is_new',
                'low_base' => 'km.low_base',
                'zero_baseline' => 'km.zero_baseline',
                'history_complete' => 'km.history_complete',
                'history_incomplete' => '(NOT km.history_complete)',
                default => null,
            };
            if ($expr !== null) {
                $wheres[] = $value ? $expr : "(NOT {$expr})";
            }
        }

        if ($payload->task !== null) {
            if (isset($payload->task['min_score'])) {
                $wheres[] = 'km.task_score >= ?';
                $whereBindings[] = $payload->task['min_score'];
            }
            if (! empty($payload->task['categories'])) {
                $cats = $payload->task['categories'];
                $ph = implode(',', array_fill(0, count($cats), '?'));
                foreach ($cats as $c) {
                    $whereBindings[] = $c;
                }
                $wheres[] = "km.task_categories ?| ARRAY[{$ph}]::text[]";
            }
        }

        if ($payload->labels !== null) {
            foreach (['any', 'all'] as $mode) {
                $labels = $payload->labels[$mode] ?? null;
                if ($labels === null) {
                    continue;
                }
                $ph = implode(',', array_fill(0, count($labels), '?'));
                foreach ($labels as $l) {
                    $whereBindings[] = $l;
                }
                if ($mode === 'any') {
                    $wheres[] = "EXISTS (SELECT 1 FROM keyword_labels kl WHERE kl.keyword_id = km.keyword_id AND kl.origin = 'manual' AND kl.label IN ({$ph}))";
                } else {
                    $wheres[] = "(SELECT count(DISTINCT kl.label) FROM keyword_labels kl WHERE kl.keyword_id = km.keyword_id AND kl.origin = 'manual' AND kl.label IN ({$ph})) = ".count($labels);
                }
            }
        }

        if ($payload->niche !== null) {
            foreach (['include_niche_id' => true, 'exclude_niche_id' => false] as $key => $shouldExist) {
                if (empty($payload->niche[$key])) {
                    continue;
                }
                $versionId = DB::table('niche_versions')
                    ->where('niche_id', $payload->niche[$key])
                    ->orderByDesc('version')
                    ->value('id');
                if ($versionId === null) {
                    $wheres[] = $shouldExist ? 'FALSE' : 'TRUE';
                    continue;
                }
                $whereBindings[] = $versionId;
                $exists = 'EXISTS (SELECT 1 FROM niche_members nm WHERE nm.keyword_id = km.keyword_id AND nm.niche_version_id = ?)';
                $wheres[] = $shouldExist ? $exists : "(NOT {$exists})";
            }
        }

        if ($payload->brands !== null && ! empty($payload->brands['dictionary_ids'])) {
            $terms = DB::table('brand_dictionaries')
                ->whereIn('id', $payload->brands['dictionary_ids'])
                ->pluck('terms');
            $all = [];
            foreach ($terms as $t) {
                foreach (json_decode((string) $t, true) ?: [] as $term) {
                    $term = mb_strtolower(trim((string) $term), 'UTF-8');
                    if ($term !== '') {
                        $all[] = $term;
                    }
                }
            }
            if ($all !== []) {
                $ph = implode(',', array_fill(0, count($all), '?'));
                foreach ($all as $t) {
                    $whereBindings[] = $t;
                }
                $wheres[] = "NOT EXISTS (SELECT 1 FROM keyword_tokens kt2 WHERE kt2.keyword_id = km.keyword_id AND kt2.token IN ({$ph}))";
            }
        }

        if ($payload->preset !== null) {
            $wheres[] = '('.Presets::conditions($payload->preset, 'km').')';
        }

        $whereSql = implode(' AND ', $wheres);

        if ($countOnly) {
            return [
                'sql' => "SELECT count(*) AS n FROM keyword_metrics km JOIN keywords k ON k.id = km.keyword_id JOIN dataset_keywords dk ON dk.keyword_id = km.keyword_id WHERE dk.dataset_id = ? AND {$whereSql}",
                'bindings' => array_merge([$datasetId], $whereBindings),
            ];
        }

        // Сортировка: NULLS LAST + вторичный ключ keyword_id (ТЗ §5.2).
        $primary = $payload->sort[0];
        $col = self::SORT_FIELDS[$primary['field']];
        if ($col === 'RANK_DELTA') {
            $col = self::RANK_DELTA_EXPR;
        }
        $dir = strtoupper($primary['direction']) === 'ASC' ? 'ASC' : 'DESC';
        $orderSql = "{$col} {$dir} NULLS LAST, km.keyword_id ASC";

        $cursorBindings = [];
        $cursorWhere = '';
        if ($payload->cursor !== null) {
            $cur = $this->decodeCursor($payload->cursor);
            $v = $cur['v'];
            $cmp = $dir === 'DESC' ? '<' : '>';
            $cursorWhere = " AND (
                ({$col} {$cmp} ? OR ({$col} IS NOT DISTINCT FROM ? AND km.keyword_id > ?))
                OR ({$col} IS NULL AND CAST(? AS text) IS NOT NULL)
            )";
            $cursorBindings = [$v, $v, $cur['id'], $v];
        }

        $sql = "SELECT km.keyword_id, k.phrase_original, k.search_text, k.word_count,
                    km.exact_current, km.broad_current, km.exact_first, km.exact_delta, km.growth_pct,
                    km.base_avg, km.late_avg, km.smoothed_delta, km.smoothed_growth, km.consistency,
                    km.peak_retention, km.task_score, km.task_categories, km.task_rule_ids, km.priority,
                    km.is_new, km.low_base, km.zero_baseline, km.history_complete, km.n_observations,
                    km.null_reasons, km.rank_current, km.rank_previous,
                    ".self::RANK_DELTA_EXPR.' AS rank_delta
                FROM keyword_metrics km
                JOIN keywords k ON k.id = km.keyword_id
                JOIN dataset_keywords dk ON dk.keyword_id = km.keyword_id
                WHERE dk.dataset_id = ? AND '.$whereSql.$cursorWhere.'
                ORDER BY '.$orderSql.'
                LIMIT '.($payload->limit + 1);

        return [
            'sql' => $sql,
            'bindings' => array_merge([$datasetId], $whereBindings, $cursorBindings),
        ];
    }

    private function termCondition(array $group, bool $negate, array &$bindings): string
    {
        $parts = [];
        foreach ($group['terms'] as $term) {
            if ($group['match'] === 'token') {
                $bindings[] = $term;
                $parts[] = 'EXISTS (SELECT 1 FROM keyword_tokens kt WHERE kt.keyword_id = km.keyword_id AND kt.token = ?)';
            } else {
                $bindings[] = '%'.$term.'%';
                $parts[] = 'k.search_text LIKE ?';
            }
        }
        $joined = $group['mode'] === 'all' ? '('.implode(' AND ', $parts).')' : '('.implode(' OR ', $parts).')';

        return $negate ? "(NOT {$joined})" : $joined;
    }

    // ====================================================================
    // Курсор
    // ====================================================================

    private function encodeCursor(object $row, array $sort, int $pos): string
    {
        $col = self::SORT_FIELDS[$sort[0]['field']];
        $value = match ($col) {
            'RANK_DELTA' => $row->rank_delta,
            'k.search_text' => $row->search_text,
            default => $row->{substr($col, 3)},
        };

        return base64_encode(json_encode(['v' => $value, 'id' => (int) $row->keyword_id, 'p' => $pos], JSON_UNESCAPED_UNICODE));
    }

    private function decodeCursor(string $cursor): array
    {
        $decoded = json_decode(base64_decode($cursor), true);
        if (! is_array($decoded) || ! isset($decoded['id'], $decoded['p'])) {
            throw new RuntimeException('INVALID_CURSOR');
        }

        return ['v' => $decoded['v'] ?? null, 'id' => (int) $decoded['id'], 'p' => (int) $decoded['p']];
    }

    // ====================================================================
    // Служебное
    // ====================================================================

    private function resolveRun(string $datasetId, ?string $metricRunId): array
    {
        if ($metricRunId !== null) {
            $run = DB::table('metric_runs')->where('id', $metricRunId)->first();
            if ($run === null || $run->dataset_id !== $datasetId) {
                throw new RuntimeException('METRIC_RUN_NOT_FOUND');
            }
            if ($run->status !== 'ready') {
                throw new RuntimeException('METRIC_RUN_NOT_READY: '.$run->status);
            }

            return [$metricRunId, $run];
        }

        $runId = $this->runs->defaultRunId($datasetId);
        if ($runId === null) {
            throw new RuntimeException('INSUFFICIENT_HISTORY: для отчёта нет готового расчёта метрик; создайте metric-run.');
        }

        return [$runId, DB::table('metric_runs')->where('id', $runId)->first()];
    }

    private function historyFor(string $datasetId, array $keywordIds): array
    {
        if ($keywordIds === []) {
            return [];
        }
        $rows = DB::select("
            SELECT o.keyword_id, s.ordinal, o.exact, o.broad
            FROM observations o
            JOIN dataset_scans s ON s.id = o.scan_id
            WHERE s.dataset_id = ? AND o.keyword_id = ANY (?::bigint[])
            ORDER BY o.keyword_id, s.ordinal
        ", [$datasetId, '{'.implode(',', $keywordIds).'}']);

        $history = [];
        foreach ($rows as $r) {
            $history[(int) $r->keyword_id][] = [
                'ordinal' => (int) $r->ordinal,
                'exact' => $r->exact !== null ? (int) $r->exact : null,
                'broad' => $r->broad !== null ? (int) $r->broad : null,
            ];
        }

        return $history;
    }

    private function formatRow(object $r, array $history, object $run): array
    {
        return [
            'keyword_id' => (int) $r->keyword_id,
            'phrase' => $r->phrase_original,
            'exact_current' => $r->exact_current !== null ? (int) $r->exact_current : null,
            'broad_current' => $r->broad_current !== null ? (int) $r->broad_current : null,
            'rank_current' => $r->rank_current !== null ? (int) $r->rank_current : null,
            'rank_previous' => $r->rank_previous !== null ? (int) $r->rank_previous : null,
            'rank_delta' => $r->rank_delta !== null ? (int) $r->rank_delta : null,
            'rank_previous_note' => ((int) $r->rank_previous === 0) ? 'не было в прошлом рейтинге' : null,
            'word_count' => $r->word_count !== null ? (int) $r->word_count : null,
            'history' => $history,
            'metrics' => [
                'exact_first' => $r->exact_first !== null ? (int) $r->exact_first : null,
                'exact_delta' => $r->exact_delta !== null ? (int) $r->exact_delta : null,
                'growth_pct' => $r->growth_pct,
                'base_avg' => $r->base_avg,
                'late_avg' => $r->late_avg,
                'smoothed_delta' => $r->smoothed_delta,
                'smoothed_growth' => $r->smoothed_growth,
                'consistency' => $r->consistency,
                'peak_retention' => $r->peak_retention,
                'task_score' => $r->task_score,
                'task_categories' => $r->task_categories ? json_decode($r->task_categories, true) : [],
                'priority' => $r->priority !== null ? (int) $r->priority : null,
                'null_reasons' => $r->null_reasons ? json_decode($r->null_reasons, true) : new \stdClass(),
            ],
            'flags' => array_values(array_filter([
                (bool) $r->is_new ? 'is_new' : null,
                (bool) $r->low_base ? 'low_base' : null,
                (bool) $r->zero_baseline ? 'zero_baseline' : null,
                ! (bool) $r->history_complete ? 'incomplete_history' : null,
            ])),
            'algorithm_version' => $run->algorithm_version,
        ];
    }
}
