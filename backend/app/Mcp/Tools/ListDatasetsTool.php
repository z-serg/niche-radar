<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_datasets')]
#[Description('Доступные отчёты и их состояние. Только опубликованные (ready) отчёты доступны для поиска.')]
class ListDatasetsTool extends Tool
{
    use McpToolSupport;

    public function handle(Request $request): Response
    {
        $rows = DB::table('datasets as d')
            ->join('source_files as f', 'f.id', '=', 'd.source_file_id')
            ->orderByDesc('d.created_at')
            ->get(['d.id', 'd.title', 'd.status', 'd.coverage_type', 'd.row_count', 'd.scan_count', 'd.is_active', 'd.region', 'd.created_at', 'f.original_name'])
            ->map(fn ($d) => [
                'dataset_id' => $d->id,
                'title' => $d->title,
                'file' => $d->original_name,
                'status' => $d->status,
                'searchable' => $d->status === 'ready',
                'is_active' => (bool) $d->is_active,
                'coverage_type' => $d->coverage_type,
                'region' => $d->region,
                'phrase_count' => $d->row_count !== null ? (int) $d->row_count : null,
                'scan_count' => $d->scan_count !== null ? (int) $d->scan_count : null,
                'uploaded_at' => $d->created_at,
            ])->all();

        return $this->jsonResponse(['datasets' => $rows]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
