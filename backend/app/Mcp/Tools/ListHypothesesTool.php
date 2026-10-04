<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\HypothesesController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_hypotheses')]
#[Description('Сохранённые гипотезы со статусами (read-only: MCP не изменяет карточки, ТЗ §5.6).')]
class ListHypothesesTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly HypothesesController $hypotheses) {}

    public function handle(Request $request): Response
    {
        $response = $this->hypotheses->index(new \Illuminate\Http\Request());
        $data = $response->getData(true);

        return $this->jsonResponse([
            'hypotheses' => $data['data'],
            'statuses' => $data['statuses'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
