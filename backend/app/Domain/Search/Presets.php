<?php

namespace App\Domain\Search;

/**
 * Пресеты отбора (ТЗ §7.2): единые условия для UI, REST и MCP.
 * Пороги берутся из config('niche.presets') и могут переопределяться
 * в профиле metric_run; значения — только из конфигурации сервера.
 */
final class Presets
{
    /**
     * SQL-условия пресета для алиаса таблицы keyword_metrics.
     */
    public static function conditions(string $key, string $alias = 'km'): string
    {
        $presets = config('niche.presets');
        if (! isset($presets[$key])) {
            throw new \InvalidArgumentException("Unknown preset: {$key}");
        }
        $p = $presets[$key];
        $conds = [];

        $minObs = (int) ($p['min_observations'] ?? 4);
        $conds[] = "{$alias}.n_observations >= {$minObs}";
        $conds[] = "{$alias}.history_complete";
        foreach ([
            'exact_current_min' => "{$alias}.exact_current >= %s",
            'base_min' => "{$alias}.base_avg >= %s",
            'base_max_exclusive' => "{$alias}.base_avg < %s",
            'smoothed_delta_min' => "{$alias}.smoothed_delta >= %s",
            'smoothed_growth_min' => "{$alias}.smoothed_growth >= %s",
            'consistency_min' => "{$alias}.consistency >= %s",
            'peak_retention_min' => "{$alias}.peak_retention >= %s",
        ] as $cfgKey => $tpl) {
            if (isset($p[$cfgKey])) {
                $conds[] = sprintf($tpl, self::num($p[$cfgKey]));
            }
        }
        if (! empty($p['base_zero'])) {
            $conds[] = "{$alias}.base_avg = 0";
        }
        if (! empty($p['smoothed_not_null'])) {
            // A определён только при полном ряде (§7.2) — как у карточек
            // «Лидеры роста/падения» на дашборде.
            $conds[] = "{$alias}.smoothed_delta IS NOT NULL";
        }

        return implode(' AND ', $conds);
    }

    /**
     * Сортировка пресета по умолчанию.
     *
     * @return array<int, array{field: string, direction: string}>
     */
    public static function defaultSort(string $key): array
    {
        return config("niche.presets.{$key}.sort", [['field' => 'priority', 'direction' => 'desc']]);
    }

    public static function titles(): array
    {
        $out = [];
        foreach (config('niche.presets', []) as $key => $p) {
            $out[$key] = $p['title'] ?? $key;
        }

        return $out;
    }

    /**
     * Человекочитаемое описание порогов пресета для UI/MCP.
     */
    public static function describe(string $key): array
    {
        $p = config("niche.presets.{$key}");
        if ($p === null) {
            throw new \InvalidArgumentException("Unknown preset: {$key}");
        }
        $reliability = match ($key) {
            'sustained_growth' => 'основной рейтинг устойчивого роста',
            'growth_leaders', 'fall_leaders' => 'ранжирование по сглаженному приросту A без порогов — те же условия, что у одноимённых карточек дашборда; это не отбор по надёжности',
            default => 'сигнал пониженной надёжности; не смешивается с основным рейтингом',
        };

        return [
            'key' => $key,
            'title' => $p['title'] ?? $key,
            'thresholds' => collect($p)->except(['title', 'sort'])->all(),
            'reliability' => $reliability,
        ];
    }

    private static function num(mixed $v): string
    {
        return is_int($v) ? (string) $v : rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
    }
}
