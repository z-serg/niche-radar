<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\HypothesesController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_hypothesis')]
#[Description('Гипотеза с доказательствами: подтверждающие фразы и сохранённые значения на момент записи.')]
class GetHypothesisTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly HypothesesController $hypotheses) {}

    public function handle(Request $request): Response
    {
        $response = $this->hypotheses->show((string) $request->get('hypothesis_id'));
        $data = $response->getData(true)['data'] ?? null;
        if ($data === null) {
            return Response::error('Гипотеза не найдена.');
        }

        return $this->jsonResponse(['hypothesis' => $data]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'hypothesis_id' => $schema->string()->required(),
        ];
    }
}
