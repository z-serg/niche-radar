<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_niche')]
#[Description('Выбранная версия оценки ниши: агрегаты, динамика, покрытие, лидеры вклада.')]
class GetNicheTool extends Tool
{
    use McpToolSupport;

    public function handle(Request $request): Response
    {
        $nicheId = (string) $request->get('niche_id');
        $query = DB::table('niche_versions')->where('niche_id', $nicheId);
        if ($request->get('version')) {
            $query->where('version', (int) $request->get('version'));
        }
        $version = $query->orderByDesc('version')->first();
        if ($version === null) {
            return Response::error('У ниши ещё нет версий состава; оцените её через UI/API.');
        }

        $niche = DB::table('niches')->where('id', $nicheId)->first();

        $members = DB::table('niche_members as nm')
            ->join('keywords as k', 'k.id', '=', 'nm.keyword_id')
            ->leftJoin('keyword_metrics as km', function ($j) use ($version) {
                $j->on('km.keyword_id', '=', 'nm.keyword_id')->where('km.metric_run_id', '=', $version->metric_run_id);
            })
            ->where('nm.niche_version_id', $version->id)
            ->orderByDesc('km.exact_current')
            ->limit($this->limit((int) $request->get('limit')))
            ->get(['nm.keyword_id', 'k.phrase_original', 'nm.has_observation', 'km.exact_current', 'km.smoothed_delta', 'km.priority'])
            ->map(fn ($m) => [
                'keyword_id' => (int) $m->keyword_id,
                'phrase' => $m->phrase_original,
                'has_observation' => (bool) $m->has_observation,
                'exact_current' => $m->exact_current !== null ? (int) $m->exact_current : null,
                'smoothed_delta' => $m->smoothed_delta,
                'priority' => $m->priority !== null ? (int) $m->priority : null,
            ]);

        return $this->jsonResponse([
            'niche' => [
                'niche_id' => $niche->id,
                'name' => $niche->name,
                'type' => $niche->type,
            ],
            'version' => [
                'niche_version_id' => $version->id,
                'version' => (int) $version->version,
                'dataset_id' => $version->dataset_id,
                'metric_run_id' => $version->metric_run_id,
                'member_count' => (int) $version->member_count,
                'complete_member_count' => (int) $version->complete_member_count,
                'coverage_pct' => (float) $version->coverage_pct,
                'aggregates' => json_decode((string) $version->aggregates, true),
                'created_at' => $version->created_at,
            ],
            'members' => $members,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'niche_id' => $schema->string()->required(),
            'version' => $schema->integer()->description('версия состава; по умолчанию последняя'),
            'limit' => $schema->integer()->description('строки состава, по умолчанию 50'),
        ];
    }
}
