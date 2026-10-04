<?php

namespace App\Domain\Metrics;

/**
 * Формулы метрик версии metrics_v1 (ТЗ §7.1, §7.2, §7.4).
 *
 * Каноническая реализация: используется юнит-тестами, карточкой фразы,
 * агрегатами ниш и сверкой bulk-расчёта в SQL. Ряд задаётся от старого
 * замера к новому; NULL — пропуск (пустая ячейка), не ноль.
 */
final class MetricFormulas
{
    public const VERSION = 'metrics_v1';

    /**
     * Расчёт метрик одного ряда точных частотностей.
     *
     * @param  array<int, int|float|null>  $series  значения от старого к новому
     * @param  int  $lowBaseThreshold      порог низкой базы (по f1)
     * @return array{f_first: ?float, f_last: ?float, exact_delta: ?float,
     *   growth_pct: ?float, base_avg: ?float, late_avg: ?float,
     *   smoothed_delta: ?float, smoothed_growth: ?float, consistency: ?float,
     *   peak_retention: ?float, series_max: ?float,
     *   null_reasons: array<string, string>}
     */
    public static function compute(array $series, int $lowBaseThreshold = 20): array
    {
        $n = count($series);
        $reasons = [];

        $known = array_values(array_filter($series, fn ($v) => $v !== null));
        $fFirst = $n > 0 ? $series[0] : null;
        $fLast = $n > 0 ? $series[$n - 1] : null;

        // --- Граничное сравнение fn − f1 (§7.1), доступно без полного ряда.
        $delta = null;
        $growthPct = null;
        if ($fFirst !== null && $fLast !== null) {
            $delta = $fLast - $fFirst;
            if ($fFirst > 0) {
                $growthPct = ($fLast - $fFirst) / (float) $fFirst;
            } else {
                $reasons['growth_pct'] = 'zero_baseline';
            }
        } elseif ($fFirst === null || $fLast === null) {
            $reasons['growth_pct'] = 'missing_boundary';
            $reasons['exact_delta'] = 'missing_boundary';
        }

        // --- Сглаженные метрики (§7.2): полный ряд из >= 4 замеров без пропусков.
        $complete = count($known) === $n && $n >= 4;
        $B = $R = $A = $G = $C = $P = null;
        $seriesMax = $known ? max($known) : null;

        if (! $complete) {
            $reasons['smoothed'] = $n < 4 ? 'insufficient_history' : 'incomplete_series';
        } else {
            $B = ($series[0] + $series[1]) / 2.0;
            $R = ($series[$n - 2] + $series[$n - 1]) / 2.0;
            $A = $R - $B;
            $G = $B > 0 ? ($R - $B) / $B : null;
            if ($G === null) {
                $reasons['smoothed_growth'] = 'zero_baseline';
            }

            $transitions = 0;
            $positive = 0;
            for ($i = 1; $i < $n; $i++) {
                $transitions++;
                if ($series[$i] > $series[$i - 1]) {
                    $positive++;
                }
            }
            $C = $transitions > 0 ? $positive / (float) $transitions : null;

            $P = ($seriesMax !== null && $seriesMax > 0) ? $series[$n - 1] / (float) $seriesMax : null;
            if ($P === null) {
                $reasons['peak_retention'] = 'zero_peak';
            }
        }

        return [
            'f_first' => $fFirst,
            'f_last' => $fLast,
            'exact_delta' => $delta,
            'growth_pct' => $growthPct,
            'base_avg' => $B,
            'late_avg' => $R,
            'smoothed_delta' => $A,
            'smoothed_growth' => $G,
            'consistency' => $C,
            'peak_retention' => $P,
            'series_max' => $seriesMax,
            'null_reasons' => $reasons,
            'low_base' => $fFirst !== null && $fFirst < $lowBaseThreshold,
        ];
    }

    /**
     * Приоритет исследования priority_v1 (§7.4). Ранжирует только фразы
     * с полной историей и базой >= порога; иначе NULL.
     *
     * @param  array{g_scale: float, a_scale: float, v_scale: float,
     *   weights: array{g: float, a: float, c: float, v: float, t: float},
     *   min_base_for_priority: float}  $profile
     */
    public static function priority(
        ?float $baseAvg,
        ?float $g,
        ?float $a,
        ?float $c,
        ?float $fn,
        float $t,
        array $profile,
    ): ?int {
        if ($baseAvg === null || $baseAvg < $profile['min_base_for_priority']) {
            return null; // низкая база / неполная история
        }
        if ($g === null || $a === null || $c === null || $fn === null) {
            return null;
        }

        $clip = fn (float $x) => min(1.0, max(0.0, $x));

        $gN = $clip($g / $profile['g_scale']);
        $aN = $clip(log(1 + max($a, 0.0)) / log(1 + $profile['a_scale']));
        $vN = $clip(log(1 + $fn) / log(1 + $profile['v_scale']));
        $tN = $clip($t);

        $score = $profile['weights']['g'] * $gN
            + $profile['weights']['a'] * $aN
            + $profile['weights']['c'] * $c
            + $profile['weights']['v'] * $vN
            + $profile['weights']['t'] * $tN;

        return (int) round(100 * $score);
    }

    /**
     * Разложение приоритета на компоненты (для UI/API, §7.4).
     *
     * @return array{priority: ?int, components: array{g: ?float, a: ?float, c: ?float, v: ?float, t: ?float}}
     */
    public static function priorityComponents(
        ?float $baseAvg,
        ?float $g,
        ?float $a,
        ?float $c,
        ?float $fn,
        float $t,
        array $profile,
    ): array {
        $priority = self::priority($baseAvg, $g, $a, $c, $fn, $t, $profile);
        if ($priority === null) {
            return ['priority' => null, 'components' => ['g' => null, 'a' => null, 'c' => null, 'v' => null, 't' => null]];
        }
        $clip = fn (float $x) => min(1.0, max(0.0, $x));

        return [
            'priority' => $priority,
            'components' => [
                'g' => $clip($g / $profile['g_scale']),
                'a' => $clip(log(1 + max($a, 0.0)) / log(1 + $profile['a_scale'])),
                'c' => $c,
                'v' => $clip(log(1 + $fn) / log(1 + $profile['v_scale'])),
                't' => $clip($t),
            ],
        ];
    }
}
