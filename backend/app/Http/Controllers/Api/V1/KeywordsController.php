<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Metrics\MetricFormulas;
use App\Domain\Metrics\MetricRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Карточка фразы (ТЗ §5.3) и её история.
 */
class KeywordsController extends Controller
{
    public function __construct(private readonly MetricRunService $runs) {}

    public function show(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['dataset_id' => ['required', 'uuid'], 'metric_run_id' => ['nullable', 'uuid']]);
        $datasetId = $data['dataset_id'];

        $keyword = DB::table('keywords')->where('id', $id)->first();
        if ($keyword === null) {
            return response()->json(['code' => 'KEYWORD_NOT_FOUND', 'message' => 'Фраза не найдена.'], 404);
        }

        $dk = DB::table('dataset_keywords')->where('dataset_id', $datasetId)->where('keyword_id', $id)->first();
        $runId = $data['metric_run_id'] ?? $this->runs->defaultRunId($datasetId);
        $run = $runId ? DB::table('metric_runs')->where('id', $runId)->first() : null;
        $km = $run ? DB::table('keyword_metrics')->where('metric_run_id', $runId)->where('keyword_id', $id)->first() : null;

        // История наблюдений с метаданными замеров.
        $history = DB::table('observations as o')
            ->join('dataset_scans as s', 's.id', '=', 'o.scan_id')
            ->where('s.dataset_id', $datasetId)
            ->where('o.keyword_id', $id)
            ->orderBy('s.ordinal')
            ->get(['s.ordinal', 's.scan_date', 's.source_suffix', 'o.exact', 'o.broad']);

        // Похожие фразы: по общим токенам и по написанию (с указанием способа).
        $tokens = DB::table('keyword_tokens')->where('keyword_id', $id)->pluck('token')->all();
        $byTokens = $tokens !== [] ? DB::select("
            SELECT k.id, k.phrase_original AS phrase, count(DISTINCT kt.token) AS shared_tokens
            FROM keywords k
            JOIN keyword_tokens kt ON kt.keyword_id = k.id
            WHERE kt.token = ANY (string_to_array(?, ',')) AND k.id <> ?
            GROUP BY k.id
            ORDER BY shared_tokens DESC, k.id
            LIMIT 15
        ", [implode(',', $tokens), $id]) : [];

        return response()->json([
            'data' => [
                'keyword_id' => (int) $keyword->id,
                'phrase' => $keyword->phrase_original,
                'search_text' => $keyword->search_text,
                'word_count' => (int) $keyword->word_count,
                'char_count' => (int) $keyword->char_count,
                'source_word_count' => $dk->word_count_source ?? null,
                'source_char_count' => $dk->char_count_source ?? null,
                'rank_current' => $km->rank_current ?? ($dk->rank_current ?? null),
                'rank_previous' => $km->rank_previous ?? ($dk->rank_previous ?? null),
                'rank_previous_note' => ((int) ($km->rank_previous ?? $dk->rank_previous ?? 0)) === 0
                    ? 'не было в прошлом рейтинге' : null,
                'present_in_dataset' => $dk !== null,
                'history' => $history->map(fn ($h) => [
                    'ordinal' => (int) $h->ordinal,
                    'scan_date' => $h->scan_date,
                    'source_suffix' => $h->source_suffix,
                    'exact' => $h->exact !== null ? (int) $h->exact : null,
                    'broad' => $h->broad !== null ? (int) $h->broad : null,
                    'note' => $h->exact === null && $h->broad === null ? 'значение отсутствует (не ноль)' : null,
                ]),
                'metrics' => $km ? $this->metricsWithComponents($km, $run) : null,
                'similar' => [
                    ['method' => 'общие токены', 'items' => $byTokens],
                    ['method' => 'похожее написание (pg_trgm)', 'items' => $this->similarByTrgm($keyword->search_text, $id)],
                ],
                'niches' => DB::table('niche_members as nm')
                    ->join('niche_versions as nv', 'nv.id', '=', 'nm.niche_version_id')
                    ->join('niches as n', 'n.id', '=', 'nv.niche_id')
                    ->where('nm.keyword_id', $id)
                    ->orderByDesc('nv.version')
                    ->get(['n.id', 'n.name', 'nv.version', 'nv.id as niche_version_id'])
                    ->unique('n.id'),
                'labels' => DB::table('keyword_labels')->where('keyword_id', $id)->get(['label', 'origin', 'rule_version']),
                'notes' => DB::table('keyword_notes')->where('keyword_id', $id)->orderByDesc('created_at')->get(['id', 'body', 'created_at']),
            ],
        ]);
    }

    /**
     * GET /keywords/{id}/history?dataset_id=… (ТЗ §9).
     */
    public function history(Request $request, int $id): JsonResponse
    {
        return $this->show($request, $id);
    }

    private function similarByTrgm(string $searchText, int $selfId): array
    {
        return DB::select("
            SELECT k.id, k.phrase_original AS phrase, similarity(k.search_text, ?) AS similarity
            FROM keywords k
            WHERE k.id <> ? AND k.search_text % ?
            ORDER BY similarity DESC, k.id
            LIMIT 15
        ", [$searchText, $selfId, $searchText]);
    }

    private function metricsWithComponents(object $km, object $run): array
    {
        $profile = json_decode((string) $run->profile, true);
        $decomposed = MetricFormulas::priorityComponents(
            $km->base_avg !== null ? (float) $km->base_avg : null,
            $km->smoothed_growth !== null ? (float) $km->smoothed_growth : null,
            $km->smoothed_delta !== null ? (float) $km->smoothed_delta : null,
            $km->consistency !== null ? (float) $km->consistency : null,
            $km->exact_current !== null ? (float) $km->exact_current : null,
            (float) $km->task_score,
            $profile,
        );

        return [
            'exact_current' => $km->exact_current !== null ? (int) $km->exact_current : null,
            'broad_current' => $km->broad_current !== null ? (int) $km->broad_current : null,
            'exact_first' => $km->exact_first !== null ? (int) $km->exact_first : null,
            'exact_delta' => $km->exact_delta !== null ? (int) $km->exact_delta : null,
            'growth_pct' => $km->growth_pct,
            'base_avg' => $km->base_avg,
            'late_avg' => $km->late_avg,
            'smoothed_delta' => $km->smoothed_delta,
            'smoothed_growth' => $km->smoothed_growth,
            'consistency' => $km->consistency,
            'peak_retention' => $km->peak_retention,
            'task_score' => $km->task_score,
            'task_categories' => $km->task_categories ? json_decode($km->task_categories, true) : [],
            'task_rule_ids' => $km->task_rule_ids ? json_decode($km->task_rule_ids, true) : [],
            'priority' => $km->priority !== null ? (int) $km->priority : null,
            'priority_components' => $decomposed['components'],
            'null_reasons' => $km->null_reasons ? json_decode($km->null_reasons, true) : new \stdClass(),
            'flags' => array_values(array_filter([
                (bool) $km->is_new ? 'is_new' : null,
                (bool) $km->low_base ? 'low_base' : null,
                (bool) $km->zero_baseline ? 'zero_baseline' : null,
                ! (bool) $km->history_complete ? 'incomplete_history' : null,
            ])),
            'formula_version' => MetricFormulas::VERSION,
            'rules_version' => $run->rules_version,
            'metric_run_id' => $run->id,
        ];
    }
}
