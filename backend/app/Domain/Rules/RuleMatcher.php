<?php

namespace App\Domain\Rules;

/**
 * Применение набора правил признаков задачи к одной фразе (ТЗ §7.3).
 * Итог T = максимум веса сработавшего правила; без совпадений T = 0.
 * Бренды и нерелевантные темы исключаются отдельными фильтрами, не штрафом.
 */
final class RuleMatcher
{
    /**
     * @param  array{version: string, rules: array<int, array<string, mixed>>}  $ruleSet
     * @param  string  $searchText  фраза в поисковой нормализации (нижний регистр)
     * @param  array<int, string>  $tokens  токены нижнего регистра
     * @return array{score: float, categories: string[], rule_ids: string[]}
     */
    public function match(array $ruleSet, string $searchText, array $tokens): array
    {
        $tokenSet = array_fill_keys($tokens, true);
        $score = 0.0;
        $categories = [];
        $ruleIds = [];

        foreach ($ruleSet['rules'] as $rule) {
            $hit = false;

            foreach ((array) ($rule['any_tokens'] ?? []) as $token) {
                if (isset($tokenSet[mb_strtolower($token, 'UTF-8')])) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                foreach ((array) ($rule['all_tokens'] ?? []) as $token) {
                    if (! isset($tokenSet[mb_strtolower($token, 'UTF-8')])) {
                        $hit = false;
                        break;
                    }
                    $hit = true;
                }
            }

            if (! $hit) {
                foreach ((array) ($rule['phrase_contains'] ?? []) as $needle) {
                    if (str_contains($searchText, mb_strtolower($needle, 'UTF-8'))) {
                        $hit = true;
                        break;
                    }
                }
            }

            if ($hit) {
                $weight = (float) ($rule['weight'] ?? 0);
                if ($weight > $score) {
                    $score = $weight;
                }
                $categories[] = (string) ($rule['category'] ?? 'other');
                $ruleIds[] = (string) ($rule['id'] ?? '');
            }
        }

        return [
            'score' => $score,
            'categories' => array_values(array_unique($categories)),
            'rule_ids' => array_values(array_unique(array_filter($ruleIds))),
        ];
    }
}
