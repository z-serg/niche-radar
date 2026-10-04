<?php

namespace App\Mcp\Tools;

use App\Domain\Metrics\MetricRunService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_candidate_groups')]
#[Description('Группы-кандидаты по токенам и парам токенов из отобранных растущих фраз, с методом получения.')]
class ListCandidateGroupsTool extends Tool
{
    use McpToolSupport;

    public function __construct(private readonly MetricRunService $runs) {}

    public function handle(Request $request): Response
    {
        $datasetId = (string) $request->get('dataset_id');
        $runId = $request->get('metric_run_id') ?: $this->runs->defaultRunId($datasetId);
        if ($runId === null) {
            return Response::error('INSUFFICIENT_HISTORY: нет готового расчёта метрик.');
        }

        $query = DB::table('candidate_groups')->where('metric_run_id', $runId);
        if ($request->get('method')) {
            $query->where('method', (string) $request->get('method'));
        }
        $rows = $query->orderByDesc('member_count')->orderBy('anchor')
            ->limit($this->limit((int) $request->get('limit')))
            ->get()
            ->map(fn ($g) => [
                'group_id' => $g->id,
                'method' => $g->method, // token | bigram
                'anchor' => $g->anchor,
                'member_count' => (int) $g->member_count,
                'exact_sum_current' => (int) $g->exact_sum_current,
                'exact_sum_label' => 'суммарная точная частотность участников группы',
                'growing_share' => (float) $g->growing_share,
                'leader_keyword_id' => (int) $g->leader_keyword_id,
                'leader_share' => (float) $g->leader_share,
                'dynamics' => json_decode((string) $g->dynamics, true),
                'note' => 'группа по словам из отобранных растущих фраз; не оценка всей темы',
            ])->all();

        $run = DB::table('metric_runs')->where('id', $runId)->first();
        $summary = $run ? json_decode((string) $run->results_summary, true) : [];

        return $this->jsonResponse([
            'groups' => $rows,
            'source_set' => $summary['groups'] ?? null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()->required(),
            'metric_run_id' => $schema->string(),
            'method' => $schema->string()->enum(['token', 'bigram']),
            'limit' => $schema->integer(),
        ];
    }
}
