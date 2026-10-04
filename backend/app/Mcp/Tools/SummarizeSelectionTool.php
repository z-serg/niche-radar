<?php

namespace App\Mcp\Tools;

use App\Domain\Analytics\SummaryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('summarize_selection')]
#[Description('Агрегаты выборки: сумма точных частотностей выбранных формулировок, медианы прироста, охват.')]
class SummarizeSelectionTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly SummaryService $summary) {}

    public function handle(Request $request): Response
    {
        $datasetId = (string) $request->get('dataset_id');

        $result = $this->summary->summary($datasetId, $request->get('metric_run_id'), (array) ($request->get('filters') ?? []));
        if (isset($result['job_id'])) {
            return $this->jsonResponse([
                'deferred' => true,
                'job_id' => $result['job_id'],
                'note' => 'Выборка велика: агрегат считается фоном; получите результат через get_job_status.',
            ]);
        }

        return $this->jsonResponse(['summary' => $result['result']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()->required(),
            'metric_run_id' => $schema->string(),
            'filters' => $schema->object()->description('те же фильтры, что в search_keywords'),
        ];
    }
}
