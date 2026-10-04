<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\AnalyticsController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_job_status')]
#[Description('Результат отложенного аналитического запроса (job_id из summarize_selection и др.).')]
class GetJobStatusTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly AnalyticsController $analytics) {}

    public function handle(Request $request): Response
    {
        $response = $this->analytics->jobStatus((string) $request->get('job_id'));
        $data = $response->getData(true)['data'] ?? null;
        if ($data === null) {
            return Response::error('Задание не найдено.');
        }

        return $this->jsonResponse(['job' => $data]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'job_id' => $schema->string()->required(),
        ];
    }
}
