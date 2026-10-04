<?php

namespace App\Domain\Search;

/**
 * Единая схема фильтров поиска для UI, REST и MCP (ТЗ §9).
 * Валидация по разрешённым полям; SQL из клиента не принимается.
 */
final class FilterPayload
{
    public readonly ?array $include;

    public readonly ?array $exclude;

    public readonly array $ranges;

    public readonly array $flags;

    public readonly ?array $task;

    public readonly ?array $labels;

    public readonly ?array $niche;

    public readonly ?array $brands;

    public readonly ?string $preset;

    /** @var array<int, array{field: string, direction: string}> */
    public readonly array $sort;

    public readonly int $limit;

    public readonly ?string $cursor;

    public function __construct(array $raw)
    {
        $raw = $raw ?: [];
        $filters = $raw['filters'] ?? $raw;

        $this->include = self::termGroup($filters['include'] ?? null);
        $this->exclude = self::termGroup($filters['exclude'] ?? null);

        $this->ranges = self::ranges($filters);
        $this->flags = self::flags($filters);
        $this->task = self::task($filters['task'] ?? null);
        $this->labels = self::labels($filters['labels'] ?? null);
        $this->niche = self::niche($filters['niche'] ?? null);
        $this->brands = self::brands($filters['brands'] ?? null);

        $preset = $filters['preset'] ?? null;
        $this->preset = is_string($preset) && array_key_exists($preset, config('niche.presets')) ? $preset : null;

        $this->sort = self::sort($raw['sort'] ?? null, $this->preset);
        $limit = (int) ($raw['limit'] ?? config('niche.search_page_default'));
        $this->limit = max(1, min($limit, (int) config('niche.search_page_max', 200)));
        $cursor = $raw['cursor'] ?? null;
        $this->cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * Канонический JSON фильтров — для кеширования и сохранённых поисков.
     */
    public function canonical(): string
    {
        return json_encode([
            'include' => $this->include,
            'exclude' => $this->exclude,
            'ranges' => $this->ranges,
            'flags' => $this->flags,
            'task' => $this->task,
            'labels' => $this->labels,
            'niche' => $this->niche,
            'brands' => $this->brands,
            'preset' => $this->preset,
            'sort' => $this->sort,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function termGroup(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $terms = array_values(array_unique(array_filter(array_map(
            fn ($t) => mb_strtolower(trim((string) $t), 'UTF-8'),
            (array) ($raw['terms'] ?? [])
        ), fn ($t) => $t !== '')));
        if ($terms === []) {
            return null;
        }

        return [
            'terms' => array_slice($terms, 0, 20),
            'mode' => ($raw['mode'] ?? 'any') === 'all' ? 'all' : 'any',
            'match' => ($raw['match'] ?? 'token') === 'substring' ? 'substring' : 'token',
        ];
    }

    private static function ranges(array $filters): array
    {
        $allowed = [
            'exact_current', 'broad_current', 'exact_delta', 'growth_pct',
            'base_avg', 'smoothed_delta', 'smoothed_growth', 'consistency',
            'peak_retention', 'task_score', 'n_observations', 'word_count', 'rank_current',
        ];
        $out = [];
        foreach ($allowed as $field) {
            $raw = $filters[$field] ?? null;
            if (! is_array($raw)) {
                continue;
            }
            $range = [];
            foreach (['min', 'max'] as $bound) {
                if (isset($raw[$bound]) && is_numeric($raw[$bound])) {
                    $range[$bound] = (float) $raw[$bound];
                }
            }
            if ($range !== []) {
                $out[$field] = $range;
            }
        }

        return $out;
    }

    private static function flags(array $filters): array
    {
        $bools = ['is_new', 'low_base', 'zero_baseline', 'history_complete', 'history_incomplete'];
        $out = [];
        foreach ($bools as $flag) {
            $v = $filters[$flag] ?? null;
            if (is_bool($v)) {
                $out[$flag] = $v;
            }
        }

        return $out;
    }

    private static function task(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        if (isset($raw['min_score']) && is_numeric($raw['min_score'])) {
            $out['min_score'] = (float) $raw['min_score'];
        }
        $categories = config('niche.task_categories');
        $validCats = array_keys($categories ?? []);
        $cats = array_values(array_intersect((array) ($raw['categories'] ?? []), $validCats));
        if ($cats !== []) {
            $out['categories'] = $cats;
        }

        return $out ?: null;
    }

    private static function labels(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (['any', 'all'] as $mode) {
            $labels = array_values(array_filter(array_map('strval', (array) ($raw[$mode] ?? []))));
            if ($labels !== []) {
                $out[$mode] = array_slice($labels, 0, 20);
            }
        }

        return $out ?: null;
    }

    private static function niche(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (['include_niche_id', 'exclude_niche_id'] as $key) {
            if (! empty($raw[$key]) && is_string($raw[$key])) {
                $out[$key] = $raw[$key];
            }
        }

        return $out ?: null;
    }

    private static function brands(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        $mode = $raw['mode'] ?? null;
        if (in_array($mode, ['exclude_any', 'exclude_all'], true)) {
            $out['mode'] = $mode;
        }
        $ids = array_values(array_filter(array_map('strval', (array) ($raw['dictionary_ids'] ?? []))));
        if ($ids !== []) {
            $out['dictionary_ids'] = array_slice($ids, 0, 10);
        }

        return $out ?: null;
    }

    /**
     * @return array<int, array{field: string, direction: string}>
     */
    private static function sort(mixed $raw, ?string $preset): array
    {
        $whitelist = KeywordSearchService::SORT_FIELDS;
        $items = [];
        if (is_array($raw)) {
            foreach (array_slice($raw, 0, 3) as $item) {
                $field = (string) ($item['field'] ?? '');
                if (! isset($whitelist[$field])) {
                    continue;
                }
                $items[] = ['field' => $field, 'direction' => ($item['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
            }
        }
        if ($items === [] && $preset !== null) {
            foreach (Presets::defaultSort($preset) as $item) {
                $field = (string) ($item['field'] ?? '');
                if (isset($whitelist[$field])) {
                    $items[] = ['field' => $field, 'direction' => ($item['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
                }
            }
        }
        if ($items === []) {
            $items[] = ['field' => 'priority', 'direction' => 'desc'];
        }

        return $items;
    }
}
