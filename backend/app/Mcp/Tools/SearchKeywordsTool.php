<?php

namespace App\Mcp\Tools;

use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use App\Domain\Search\Presets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search_keywords')]
#[Description('Поиск фраз по структурированным фильтрам с метриками. По 50 строк по умолчанию (макс. 200), курсор для продолжения.')]
class SearchKeywordsTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly KeywordSearchService $search) {}

    public function handle(Request $request): Response
    {
        $datasetId = (string) $request->get('dataset_id');
        $status = \Illuminate\Support\Facades\DB::table('datasets')->where('id', $datasetId)->value('status');
        if ($status === null) {
            return Response::error('Отчёт не найден: '.$datasetId);
        }
        if ($status !== 'ready') {
            return Response::error("DATASET_NOT_READY: отчёт не опубликован ({$status}). Поиск доступен только по опубликованным отчётам.");
        }

        $body = [
            'dataset_id' => $datasetId,
            'metric_run_id' => $request->get('metric_run_id'),
            'filters' => (array) ($request->get('filters') ?? []),
            'sort' => $request->get('sort'),
            'limit' => $this->limit($request->get('limit') !== null ? (int) $request->get('limit') : null),
            'cursor' => $request->get('cursor'),
        ];

        try {
            $payload = new FilterPayload($body);
            $result = $this->search->search($datasetId, $body['metric_run_id'], $payload);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return $this->jsonResponse([
            'data' => $result['data'],
            'next_cursor' => $result['next_cursor'],
            'truncated' => $result['truncated'],
            'hard_limit_reached' => $result['hard_limit_reached'],
            'total_count' => null,
            'dataset_id' => $datasetId,
            'metric_run_id' => $body['metric_run_id'],
            'applied' => $result['applied'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()->description('UUID отчёта (готовый расчёт метрик используется по умолчанию)')->required(),
            'metric_run_id' => $schema->string()->description('UUID прогона метрик; по умолчанию последний готовый полный диапазон'),
            'filters' => $schema->object([
                'include' => $schema->object([
                    'terms' => $schema->array()->items($schema->string())->description('токены или подстроки, нижний регистр'),
                    'mode' => $schema->string()->enum(['any', 'all'])->description('любое из слов / все слова'),
                    'match' => $schema->string()->enum(['token', 'substring'])->description('токен целиком или подстрока'),
                ]),
                'exclude' => $schema->object([
                    'terms' => $schema->array()->items($schema->string()),
                    'match' => $schema->string()->enum(['token', 'substring']),
                ]),
                'exact_current' => $schema->object([
                    'min' => $schema->number(),
                    'max' => $schema->number(),
                ]),
                'smoothed_growth' => $schema->object(['min' => $schema->number()])->description('G, доли: 0.30 = +30%'),
                'smoothed_delta' => $schema->object(['min' => $schema->number()])->description('A, абсолютный сглаженный прирост'),
                'consistency' => $schema->object(['min' => $schema->number()]),
                'preset' => $schema->string()->enum(array_keys(config('niche.presets')))->description('пресет отбора'),
                'history_complete' => $schema->boolean(),
            ]),
            'sort' => $schema->array()->items($schema->object([
                'field' => $schema->string()->enum(array_keys(KeywordSearchService::SORT_FIELDS)),
                'direction' => $schema->string()->enum(['asc', 'desc']),
            ])),
            'limit' => $schema->integer()->description('строк в ответе, по умолчанию 50, максимум 200'),
            'cursor' => $schema->string()->description('курсор предыдущего ответа'),
        ];
    }
}
