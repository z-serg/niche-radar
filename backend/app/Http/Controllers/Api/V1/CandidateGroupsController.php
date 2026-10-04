<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Groups\CandidateGroupBuilder;
use App\Domain\Metrics\MetricRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Предложения групп (ТЗ §5.4, §7.6).
 */
class CandidateGroupsController extends Controller
{
    public function __construct(private readonly MetricRunService $runs) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'metric_run_id' => ['nullable', 'uuid'],
            'dataset_id' => ['required_with:metric_run_id', 'uuid'],
            'method' => ['nullable', 'in:token,bigram'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $runId = $data['metric_run_id'] ?? $this->runs->defaultRunId($data['dataset_id'] ?? '');
        if ($runId === null) {
            return response()->json(['code' => 'INSUFFICIENT_HISTORY', 'message' => 'Нет готового расчёта метрик.'], 422);
        }

        $query = DB::table('candidate_groups')->where('metric_run_id', $runId);
        if (! empty($data['method'])) {
            $query->where('method', $data['method']);
        }
        $groups = $query->orderByDesc('member_count')->orderBy('anchor')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        $run = DB::table('metric_runs')->where('id', $runId)->first();
        $summary = $run ? json_decode((string) $run->results_summary, true) : [];

        return response()->json([
            'data' => $groups->map(fn ($g) => [
                'id' => $g->id,
                'metric_run_id' => $g->metric_run_id,
                'method' => $g->method,
                'anchor' => $g->anchor,
                'member_count' => (int) $g->member_count,
                'exact_sum_current' => (int) $g->exact_sum_current,
                'growing_share' => (float) $g->growing_share,
                'leader_keyword_id' => (int) $g->leader_keyword_id,
                'leader_share' => (float) $g->leader_share,
                'dynamics' => json_decode((string) $g->dynamics, true),
                'description' => 'группа по словам из отобранных растущих фраз',
            ]),
            'source_set' => $summary['groups'] ?? null,
        ]);
    }

    /**
     * Участники группы; expand=true — по всему отчёту, включая нерастущие
     * (действие «Расширить», ТЗ §7.6).
     */
    public function members(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'expand' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $group = DB::table('candidate_groups')->where('id', $id)->first();
        if ($group === null) {
            return response()->json(['code' => 'GROUP_NOT_FOUND', 'message' => 'Группа не найдена.'], 404);
        }

        $limit = (int) ($data['limit'] ?? 50);
        $offset = (int) ($data['offset'] ?? 0);

        if (! empty($data['expand'])) {
            $members = CandidateGroupBuilder::members($data['dataset_id'], $group->metric_run_id, $group->method, $group->anchor, $limit, $offset, onlyGrowing: false);
        } else {
            $members = CandidateGroupBuilder::members($data['dataset_id'], $group->metric_run_id, $group->method, $group->anchor, $limit, $offset, onlyGrowing: true);
        }

        return response()->json([
            'data' => $members,
            'expanded' => ! empty($data['expand']),
            'note' => ! empty($data['expand'])
                ? 'Состав по всему отчёту (включая нерастущие фразы): проверьте перед сохранением ниши'
                : 'Состав из отобранных растущих фраз',
        ]);
    }
}
