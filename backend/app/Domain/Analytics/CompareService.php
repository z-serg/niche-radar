<?php

namespace App\Domain\Analytics;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Сравнение двух отчётов (ТЗ §7.5): только текущие замеры, только при
 * совпадении региона и определения частотности; рост считается на
 * пересечении фраз, вошедшие/отсутствующие показываются отдельно,
 * отсутствие не заменяется нулём.
 */
class CompareService
{
    public function compare(string $baseId, string $currentId, ?string $nicheId = null, int $sampleSize = 50): array
    {
        $base = DB::table('datasets')->where('id', $baseId)->first();
        $current = DB::table('datasets')->where('id', $currentId)->first();
        if ($base === null || $current === null) {
            throw new RuntimeException('DATASET_NOT_FOUND');
        }
        foreach ([$base, $current] as $ds) {
            if ($ds->status !== 'ready') {
                throw new RuntimeException('DATASET_NOT_READY');
            }
        }

        // Совместимость замеров (§7.5).
        if (($base->frequency_definition ?? null) !== ($current->frequency_definition ?? null)) {
            throw new RuntimeException('INCOMPATIBLE_MEASUREMENTS: определения частотности различаются');
        }
        $regionCompatible = ($base->region ?? null) === ($current->region ?? null);
        if (! $regionCompatible) {
            throw new RuntimeException('INCOMPATIBLE_MEASUREMENTS: регионы различаются');
        }

        $baseScan = $this->currentScan($baseId);
        $currentScan = $this->currentScan($currentId);

        // Ограничение по нише: последняя версия состава.
        $nicheNote = null;
        $nicheFilter = null;
        if ($nicheId !== null) {
            $version = DB::table('niche_versions')->where('niche_id', $nicheId)->orderByDesc('version')->first();
            if ($version === null) {
                throw new RuntimeException('NICHE_VERSION_NOT_FOUND');
            }
            $nicheFilter = DB::table('niche_members')->where('niche_version_id', $version->id)->pluck('keyword_id')->all();
            $nicheNote = [
                'niche_id' => $nicheId,
                'niche_version_id' => $version->id,
                'version' => (int) $version->version,
            ];
        }

        // Текущие значения обоих отчётов.
        $baseVals = $this->currentValues($baseScan->id, $nicheFilter);
        $currentVals = $this->currentValues($currentScan->id, $nicheFilter);

        $intersection = array_intersect(array_keys($baseVals), array_keys($currentVals));
        $sumBase = 0;
        $sumCurrent = 0;
        foreach ($intersection as $id) {
            $sumBase += $baseVals[$id]['exact'];
            $sumCurrent += $currentVals[$id]['exact'];
        }
        $growthAbs = $sumCurrent - $sumBase;
        $growthPct = $sumBase > 0 ? $growthAbs / $sumBase : null;

        $entered = array_diff(array_keys($currentVals), array_keys($baseVals));
        $left = array_diff(array_keys($baseVals), array_keys($currentVals));

        return [
            'base_dataset' => $this->datasetBrief($base, $baseScan),
            'current_dataset' => $this->datasetBrief($current, $currentScan),
            'compatibility' => [
                'region' => $base->region,
                'frequency_definition' => $base->frequency_definition,
                'period_known' => $baseScan->scan_date !== null && $currentScan->scan_date !== null,
                'note' => $baseScan->scan_date === null || $currentScan->scan_date === null
                    ? 'период частотности неизвестен: сравниваются порядковые замеры, календарные темпы не рассчитываются'
                    : null,
            ],
            'niche' => $nicheNote,
            'intersection' => [
                'phrase_count' => count($intersection),
                // Подпись: сумма точных на пересечении, не весь рынок (§4.1).
                'exact_sum_label' => 'суммарная точная частотность общих формулировок (пересечение отчётов)',
                'base_exact_sum' => $sumBase,
                'current_exact_sum' => $sumCurrent,
                'growth_abs' => $growthAbs,
                'growth_pct' => $growthPct,
            ],
            'entered' => [ // есть в текущем, нет в базовом; отсутствие не ноль
                'phrase_count' => count($entered),
                'sample' => $this->topList($currentVals, $entered, $sampleSize),
            ],
            'left' => [
                'phrase_count' => count($left),
                'sample' => $this->topList($baseVals, $left, $sampleSize),
            ],
            'top_changes' => $this->topChanges($baseVals, $currentVals, $intersection, $sampleSize),
        ];
    }

    private function currentScan(string $datasetId): object
    {
        $scan = DB::table('dataset_scans')->where('dataset_id', $datasetId)->orderByDesc('ordinal')->first();
        if ($scan === null) {
            throw new RuntimeException('INSUFFICIENT_HISTORY: у отчёта нет замеров');
        }

        return $scan;
    }

    /**
     * @return array<int, array{exact: int, broad: ?int, phrase: string, rank: ?int}>
     */
    private function currentValues(string $scanId, ?array $nicheFilter): array
    {
        $query = DB::table('observations as o')
            ->join('keywords as k', 'k.id', '=', 'o.keyword_id')
            ->where('o.scan_id', $scanId)
            ->whereNotNull('o.exact')
            ->select('o.keyword_id', 'o.exact', 'o.broad', 'k.phrase_original');
        if ($nicheFilter !== null) {
            $query->whereIn('o.keyword_id', $nicheFilter);
        }
        $out = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->keyword_id] = [
                'exact' => (int) $row->exact,
                'broad' => $row->broad !== null ? (int) $row->broad : null,
                'phrase' => $row->phrase_original,
            ];
        }

        return $out;
    }

    private function topList(array $values, array $ids, int $limit): array
    {
        $list = [];
        foreach ($ids as $id) {
            if (isset($values[$id])) {
                $list[] = ['keyword_id' => (int) $id] + $values[$id];
            }
        }
        usort($list, fn ($a, $b) => $b['exact'] <=> $a['exact']);

        return array_slice($list, 0, $limit);
    }

    private function topChanges(array $baseVals, array $currentVals, array $ids, int $limit): array
    {
        $list = [];
        foreach ($ids as $id) {
            $delta = $currentVals[$id]['exact'] - $baseVals[$id]['exact'];
            $list[] = [
                'keyword_id' => (int) $id,
                'phrase' => $currentVals[$id]['phrase'],
                'base_exact' => $baseVals[$id]['exact'],
                'current_exact' => $currentVals[$id]['exact'],
                'delta' => $delta,
            ];
        }
        usort($list, fn ($a, $b) => $b['delta'] <=> $a['delta']);

        return [
            'growth' => array_slice($list, 0, $limit),
            'fall' => array_slice(array_reverse($list), 0, $limit),
        ];
    }

    private function datasetBrief(object $dataset, object $scan): array
    {
        return [
            'id' => $dataset->id,
            'title' => $dataset->title,
            'coverage_type' => $dataset->coverage_type,
            'row_count' => $dataset->row_count !== null ? (int) $dataset->row_count : null,
            'current_scan' => [
                'ordinal' => (int) $scan->ordinal,
                'scan_date' => $scan->scan_date,
                'source_header' => $scan->source_header,
            ],
        ];
    }
}
