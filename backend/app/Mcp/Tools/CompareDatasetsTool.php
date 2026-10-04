<?php

namespace App\Mcp\Tools;

use App\Domain\Analytics\CompareService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('compare_datasets')]
#[Description('Сравнение двух отчётов по текущим замерам: динамика общей части и отдельные списки входа/выхода.')]
class CompareDatasetsTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly CompareService $compare) {}

    public function handle(Request $request): Response
    {
        try {
            $result = $this->compare->compare(
                (string) $request->get('base_dataset_id'),
                (string) $request->get('current_dataset_id'),
                $request->get('niche_id'),
            );
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return $this->jsonResponse(['comparison' => $result]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'base_dataset_id' => $schema->string()->description('базовый отчёт (старее)')->required(),
            'current_dataset_id' => $schema->string()->description('текущий отчёт (новее)')->required(),
            'niche_id' => $schema->string()->description('опционально: сравнивать только состав ниши'),
        ];
    }
}
