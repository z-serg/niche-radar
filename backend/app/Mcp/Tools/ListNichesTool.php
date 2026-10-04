<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_niches')]
#[Description('Сохранённые ниши: тип, размер последней версии состава, покрытие полным рядом.')]
class ListNichesTool extends Tool
{
    use McpToolSupport;

    public function handle(Request $request): Response
    {
        $rows = DB::table('niches as n')
            ->leftJoin(DB::raw('(SELECT niche_id, max(version) AS v, max(member_count) AS members FROM niche_versions GROUP BY niche_id) nv'), 'nv.niche_id', '=', 'n.id')
            ->orderBy('n.name')
            ->get(['n.id', 'n.name', 'n.description', 'n.type', 'nv.v', 'nv.members']);

        return $this->jsonResponse([
            'niches' => $rows->map(fn ($n) => [
                'niche_id' => $n->id,
                'name' => $n->name,
                'description' => $n->description,
                'type' => $n->type,
                'last_version' => $n->v !== null ? (int) $n->v : null,
                'last_member_count' => $n->members !== null ? (int) $n->members : null,
            ]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
