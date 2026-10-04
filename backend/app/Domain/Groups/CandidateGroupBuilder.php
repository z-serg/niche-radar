<?php

namespace App\Domain\Groups;

use App\Domain\Rules\RuleSetRegistry;
use App\Domain\Search\Presets;
use App\Support\Tokenizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Автопредложения групп (ТЗ §7.6): группы по одиночным токенам и
 * соседним парам из отобранных растущих фраз (до 100 000 лидеров
 * приоритета), после исключения стоп-слов. Минимум пять фраз в группе;
 * группы с одинаковым составом схлопываются детерминированно.
 */
class CandidateGroupBuilder
{
    private const STORED_GROUPS_CAP = 2000;

    /**
     * @return array{source_set_size: int, limited: bool, groups_found: int, groups_stored: int, covered_members: int}
     */
    public function build(string $runId, string $datasetId): array
    {
        $limit = (int) config('niche.groups.top_set_limit');
        $minSize = (int) config('niche.groups.min_group_size');
        $stopwords = array_fill_keys(
            array_map(fn ($w) => mb_strtolower(trim($w), 'UTF-8'), RuleSetRegistry::activeStopwords()['words'] ?? []),
            true
        );

        // --- Исходный набор: фразы пресета «Устойчивый рост» ---
        $presetConds = Presets::conditions('sustained_growth', 'km');
        $rows = DB::select("
            SELECT km.keyword_id, k.search_text, km.exact_current, km.smoothed_delta, km.task_score
            FROM keyword_metrics km
            JOIN keywords k ON k.id = km.keyword_id
            WHERE km.metric_run_id = ? AND {$presetConds}
            ORDER BY km.priority DESC NULLS LAST, km.keyword_id ASC
            LIMIT ".($limit + 1)."
        ", [$runId]);

        $limited = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $sourceSetSize = count($rows);
        if ($sourceSetSize === 0) {
            DB::table('candidate_groups')->where('metric_run_id', $runId)->delete();

            return ['source_set_size' => 0, 'limited' => false, 'groups_found' => 0, 'groups_stored' => 0, 'covered_members' => 0];
        }

        // --- Группы по токенам и парам ---
        $groups = []; // anchor => ['members' => [id => meta]]
        foreach ($rows as $r) {
            $tokens = array_values(array_filter(
                Tokenizer::searchTokens($r->search_text),
                fn ($t) => ! isset($stopwords[$t]) && mb_strlen($t, 'UTF-8') >= 2
            ));
            foreach ($tokens as $t) {
                $groups['token:'.$t][$r->keyword_id] = $r;
            }
            foreach (Tokenizer::bigrams($tokens) as $bg) {
                if (isset($stopwords[$bg])) {
                    continue;
                }
                $groups['bigram:'.$bg][$r->keyword_id] = $r;
            }
        }

        // Минимум N разных фраз.
        $groups = array_filter($groups, fn ($members) => count($members) >= $minSize);

        // Схлопывание одинакового состава: детерминированно оставляем
        // группу с наименьшим якорем.
        $byComposition = [];
        foreach ($groups as $anchor => $members) {
            $ids = array_keys($members);
            sort($ids);
            $hash = md5(implode(',', $ids));
            if (! isset($byComposition[$hash]) || strcmp($anchor, $byComposition[$hash]['anchor']) < 0) {
                $byComposition[$hash] = ['anchor' => $anchor, 'members' => $members, 'ids' => $ids];
            }
        }

        // --- Динамика: суммы точных частотностей участников по замерам ---
        $ordinalFrom = (int) DB::table('metric_runs')->where('id', $runId)->value('ordinal_from');
        $ordinalTo = (int) DB::table('metric_runs')->where('id', $runId)->value('ordinal_to');
        $memberToGroup = [];
        foreach ($byComposition as $entry) {
            foreach ($entry['ids'] as $id) {
                $memberToGroup[$id][] = $entry['anchor'];
            }
        }
        $dynamics = []; // anchor => [ordinal => sum]
        $chunkSize = 5000;
        $allIds = array_keys($memberToGroup);
        foreach (array_chunk($allIds, $chunkSize) as $chunk) {
            $obsRows = DB::select("
                SELECT o.keyword_id, s.ordinal, o.exact
                FROM observations o
                JOIN dataset_scans s ON s.id = o.scan_id
                WHERE s.dataset_id = ? AND s.ordinal BETWEEN ? AND ?
                  AND o.keyword_id = ANY (?::bigint[])
            ", [$datasetId, $ordinalFrom, $ordinalTo, '{'.implode(',', $chunk).'}']);
            foreach ($obsRows as $o) {
                foreach ($memberToGroup[$o->keyword_id] ?? [] as $anchor) {
                    if ($o->exact !== null) {
                        $dynamics[$anchor][$o->ordinal] = ($dynamics[$anchor][$o->ordinal] ?? 0) + $o->exact;
                    }
                }
            }
        }

        // --- Запись групп ---
        DB::table('candidate_groups')->where('metric_run_id', $runId)->delete();

        $prepared = [];
        foreach ($byComposition as $entry) {
            $anchor = $entry['anchor'];
            [$method, $anchorText] = explode(':', $anchor, 2);
            $members = $entry['members'];
            $exactSum = 0;
            $leader = null;
            $growing = 0;
            foreach ($members as $m) {
                $exactSum += (int) $m->exact_current;
                if ($leader === null || $m->exact_current > $leader->exact_current) {
                    $leader = $m;
                }
                if ((float) $m->smoothed_delta > 0) {
                    $growing++;
                }
            }
            $prepared[] = [
                'method' => $method,
                'anchor' => $anchorText,
                'member_count' => count($members),
                'exact_sum_current' => $exactSum,
                'growing_share' => count($members) > 0 ? $growing / count($members) : 0,
                'leader_keyword_id' => $leader->keyword_id,
                'leader_share' => $exactSum > 0 ? $leader->exact_current / $exactSum : 0,
                'dynamics' => isset($dynamics[$anchor]) ? $dynamics[$anchor] : null,
            ];
        }

        usort($prepared, fn ($a, $b) => [$b['member_count'], $b['exact_sum_current'], $a['anchor']] <=> [$a['member_count'], $a['exact_sum_current'], $b['anchor']]);

        $stored = array_slice($prepared, 0, self::STORED_GROUPS_CAP);
        $now = now();
        foreach (array_chunk($stored, 500) as $batch) {
            DB::table('candidate_groups')->insert(array_map(fn ($g) => [
                'id' => (string) Str::uuid(),
                'metric_run_id' => $runId,
                'method' => $g['method'],
                'anchor' => $g['anchor'],
                'member_count' => $g['member_count'],
                'exact_sum_current' => $g['exact_sum_current'],
                'growing_share' => $g['growing_share'],
                'leader_keyword_id' => $g['leader_keyword_id'],
                'leader_share' => $g['leader_share'],
                'dynamics' => $g['dynamics'] ? json_encode($g['dynamics']) : null,
                'source_set_size' => $sourceSetSize,
                'created_at' => $now,
                'updated_at' => $now,
            ], $batch));
        }

        // Охват исходного набора — по всем найденным группам, не только сохранённым.
        $covered = [];
        foreach ($byComposition as $entry) {
            foreach ($entry['ids'] as $id) {
                $covered[$id] = true;
            }
        }

        return [
            'source_set_size' => $sourceSetSize,
            'limited' => $limited,
            'groups_found' => count($prepared),
            'groups_stored' => count($stored),
            'covered_members' => count($covered),
        ];
    }

    /**
     * Участники группы по якорю (для примеров и «Расширить», ТЗ §7.6).
     * $onlyGrowing=false — поиск по всему отчёту, включая нерастущие фразы.
     *
     * @return array<int, object>
     */
    public static function members(string $datasetId, string $metricRunId, string $method, string $anchor, int $limit = 200, int $offset = 0, bool $onlyGrowing = true): array
    {
        if ($method === 'token') {
            $match = 'EXISTS (SELECT 1 FROM keyword_tokens kt WHERE kt.keyword_id = km.keyword_id AND kt.token = ?)';
            $binding = [$anchor];
        } else {
            $match = 'k.search_text LIKE ?';
            $binding = ['%'.$anchor.'%'];
        }
        $extra = $onlyGrowing ? ' AND ('.Presets::conditions('sustained_growth', 'km').')' : '';

        return DB::select("
            SELECT km.keyword_id, k.phrase_original, km.exact_current, km.broad_current,
                   km.smoothed_delta, km.smoothed_growth, km.consistency, km.peak_retention,
                   km.priority, km.is_new
            FROM keyword_metrics km
            JOIN keywords k ON k.id = km.keyword_id
            WHERE km.metric_run_id = ? AND {$match}{$extra}
            ORDER BY km.exact_current DESC, km.keyword_id ASC
            LIMIT ? OFFSET ?
        ", array_merge([$metricRunId], $binding, [$limit, $offset]));
    }
}
