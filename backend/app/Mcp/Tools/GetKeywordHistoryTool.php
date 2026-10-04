<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\KeywordsController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_keyword_history')]
#[Description('Полная доступная история одной фразы в отчёте: обе частотности, ранги, метрики и причины NULL.')]
class GetKeywordHistoryTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly KeywordsController $keywords) {}

    public function handle(Request $request): Response
    {
        $keywordId = (int) $request->get('keyword_id');
        $datasetId = (string) $request->get('dataset_id');

        $response = $this->keywords->show(
            new \Illuminate\Http\Request(['dataset_id' => $datasetId, 'metric_run_id' => $request->get('metric_run_id')]),
            $keywordId
        );
        $data = $response->getData(true)['data'] ?? null;
        if ($data === null) {
            return Response::error('Фраза не найдена в указанном отчёте.');
        }

        return $this->jsonResponse(['keyword' => $data]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword_id' => $schema->integer()->description('ID фразы')->required(),
            'dataset_id' => $schema->string()->description('UUID отчёта')->required(),
            'metric_run_id' => $schema->string()->description('UUID прогона метрик'),
        ];
    }
}
