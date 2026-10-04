<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Niches\NicheEvaluator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NichesController extends Controller
{
    public function __construct(private readonly NicheEvaluator $evaluator) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('niches as n')
            ->leftJoin(DB::raw('(SELECT niche_id, max(version) AS last_version, max(created_at) AS last_evaluated FROM niche_versions GROUP BY niche_id) v'), 'v.niche_id', '=', 'n.id')
            ->orderBy('n.name')
            ->get(['n.*', 'v.last_version', 'v.last_evaluated']);

        return response()->json([
            'data' => $rows->map(fn ($n) => [
                'id' => $n->id,
                'name' => $n->name,
                'description' => $n->description,
                'type' => $n->type,
                'rule' => json_decode((string) $n->rule, true),
                'manual_include_count' => count(json_decode((string) ($n->manual_include ?? '[]'), true) ?: []),
                'manual_exclude_count' => count(json_decode((string) ($n->manual_exclude ?? '[]'), true) ?: []),
                'last_version' => $n->last_version !== null ? (int) $n->last_version : null,
                'last_evaluated_at' => $n->last_evaluated,
                'created_at' => $n->created_at,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('niches')->insert([
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'fixed',
            'rule' => isset($data['rule']) ? json_encode($data['rule'], JSON_UNESCAPED_UNICODE) : null,
            'manual_include' => json_encode(array_values(array_unique(array_map('intval', $data['manual_include'] ?? [])))),
            'manual_exclude' => json_encode(array_values(array_unique(array_map('intval', $data['manual_exclude'] ?? [])))),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function show(string $id): JsonResponse
    {
        $niche = DB::table('niches')->where('id', $id)->first();
        if ($niche === null) {
            return response()->json(['code' => 'NICHE_NOT_FOUND', 'message' => 'Ниша не найдена.'], 404);
        }
        $versions = DB::table('niche_versions')->where('niche_id', $id)->orderByDesc('version')->get();

        return response()->json([
            'data' => [
                'id' => $niche->id,
                'name' => $niche->name,
                'description' => $niche->description,
                'type' => $niche->type,
                'rule' => json_decode((string) $niche->rule, true),
                'manual_include' => json_decode((string) ($niche->manual_include ?? '[]'), true) ?: [],
                'manual_exclude' => json_decode((string) ($niche->manual_exclude ?? '[]'), true) ?: [],
                'versions' => $versions->map(fn ($v) => [
                    'id' => $v->id,
                    'version' => (int) $v->version,
                    'dataset_id' => $v->dataset_id,
                    'metric_run_id' => $v->metric_run_id,
                    'member_count' => (int) $v->member_count,
                    'complete_member_count' => (int) $v->complete_member_count,
                    'coverage_pct' => (float) $v->coverage_pct,
                    'aggregates' => json_decode((string) $v->aggregates, true),
                    'created_at' => $v->created_at,
                ]),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (DB::table('niches')->where('id', $id)->doesntExist()) {
            return response()->json(['code' => 'NICHE_NOT_FOUND', 'message' => 'Ниша не найдена.'], 404);
        }
        $data = $this->validated($request, isUpdate: true);

        $update = ['updated_at' => now()];
        foreach (['name', 'description', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        if (array_key_exists('rule', $data)) {
            $update['rule'] = isset($data['rule']) ? json_encode($data['rule'], JSON_UNESCAPED_UNICODE) : null;
        }
        foreach (['manual_include', 'manual_exclude'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = json_encode(array_values(array_unique(array_map('intval', $data[$field] ?? []))));
            }
        }
        DB::table('niches')->where('id', $id)->update($update);

        return $this->show($id);
    }

    public function destroy(string $id): JsonResponse
    {
        $deps = DB::table('hypotheses')->where('niche_id', $id)->count();
        if ($deps > 0) {
            return response()->json([
                'code' => 'NICHE_HAS_DEPENDENCIES',
                'message' => "На нишу ссылаются гипотезы ({$deps}). Удалите или отвяжите их.",
            ], 409);
        }
        DB::table('niches')->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /niches/{id}/evaluate — новая версия состава и оценки (ТЗ §9).
     */
    public function evaluate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'metric_run_id' => ['nullable', 'uuid'],
        ]);

        try {
            $result = $this->evaluator->evaluate($id, $data['dataset_id'], $data['metric_run_id'] ?? null);
        } catch (\RuntimeException $e) {
            $code = strtok($e->getMessage(), ':');

            return response()->json(['code' => $code, 'message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    /**
     * Участники выбранной версии.
     */
    public function members(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $query = DB::table('niche_versions')->where('niche_id', $id);
        if (! empty($data['version'])) {
            $query->where('version', $data['version']);
        }
        $version = $query->orderByDesc('version')->first();
        if ($version === null) {
            return response()->json(['code' => 'NICHE_VERSION_NOT_FOUND', 'message' => 'Версии состава ещё нет; запустите оценку.'], 404);
        }

        $members = DB::table('niche_members as nm')
            ->join('keywords as k', 'k.id', '=', 'nm.keyword_id')
            ->leftJoin('keyword_metrics as km', function ($j) use ($version) {
                $j->on('km.keyword_id', '=', 'nm.keyword_id')->where('km.metric_run_id', '=', $version->metric_run_id);
            })
            ->where('nm.niche_version_id', $version->id)
            ->orderByDesc('km.exact_current')
            ->orderBy('nm.keyword_id')
            ->limit((int) ($data['limit'] ?? 200))
            ->get(['nm.keyword_id', 'k.phrase_original', 'nm.has_observation', 'km.exact_current', 'km.smoothed_delta', 'km.smoothed_growth', 'km.priority']);

        return response()->json([
            'data' => $members,
            'version' => ['id' => $version->id, 'version' => (int) $version->version, 'created_at' => $version->created_at],
        ]);
    }

    private function validated(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'in:fixed,rule'],
            'rule' => ['nullable', 'array'],
            'manual_include' => ['nullable', 'array'],
            'manual_include.*' => ['integer'],
            'manual_exclude' => ['nullable', 'array'],
            'manual_exclude.*' => ['integer'],
        ];

        return $request->validate($rules);
    }
}
