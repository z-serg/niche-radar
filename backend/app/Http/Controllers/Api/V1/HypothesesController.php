<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exports\ExportProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HypothesesController extends Controller
{
    public const STATUSES = ['candidate', 'researching', 'testing', 'building', 'parked', 'rejected'];

    public const STATUS_LABELS = [
        'candidate' => 'кандидат',
        'researching' => 'исследую',
        'testing' => 'проверяю',
        'building' => 'в разработке',
        'parked' => 'отложено',
        'rejected' => 'отклонено',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('hypotheses as h')
            ->leftJoin('niches as n', 'n.id', '=', 'h.niche_id')
            ->orderByDesc('h.updated_at');
        if ($request->filled('status')) {
            $query->where('h.status', $request->string('status'));
        }
        if ($request->filled('niche_id')) {
            $query->where('h.niche_id', $request->string('niche_id'));
        }

        $rows = $query->get(['h.*', 'n.name as niche_name']);

        return response()->json([
            'data' => $rows->map(fn ($h) => $this->format($h)),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('hypotheses')->insert([
            'id' => $id,
            'niche_id' => $data['niche_id'] ?? null,
            'title' => $data['title'],
            'audience' => $data['audience'] ?? null,
            'problem' => $data['problem'] ?? null,
            'current_solution' => $data['current_solution'] ?? null,
            'product_idea' => $data['product_idea'] ?? null,
            'mvp' => $data['mvp'] ?? null,
            'monetization' => $data['monetization'] ?? null,
            'notes' => $data['notes'] ?? null,
            'research_links' => $data['research_links'] ?? null,
            'next_experiment' => $data['next_experiment'] ?? null,
            'success_criterion' => $data['success_criterion'] ?? null,
            'status' => $data['status'] ?? 'candidate',
            'status_reason' => $data['status_reason'] ?? null,
            'dataset_id' => $data['dataset_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function show(string $id): JsonResponse
    {
        $h = DB::table('hypotheses as h')
            ->leftJoin('niches as n', 'n.id', '=', 'h.niche_id')
            ->where('h.id', $id)
            ->first(['h.*', 'n.name as niche_name']);
        if ($h === null) {
            return response()->json(['code' => 'HYPOTHESIS_NOT_FOUND', 'message' => 'Гипотеза не найдена.'], 404);
        }

        $evidence = DB::table('hypothesis_evidence as e')
            ->join('keywords as k', 'k.id', '=', 'e.keyword_id')
            ->where('e.hypothesis_id', $id)
            ->orderByDesc('e.created_at')
            ->get(['e.id', 'e.keyword_id', 'k.phrase_original', 'e.snapshot', 'e.dataset_id', 'e.metric_run_id', 'e.niche_version_id']);

        return response()->json(['data' => $this->format($h) + [
            'evidence' => $evidence->map(fn ($e) => [
                'id' => $e->id,
                'keyword_id' => (int) $e->keyword_id,
                'phrase' => $e->phrase_original,
                'snapshot' => json_decode((string) $e->snapshot, true),
                'dataset_id' => $e->dataset_id,
                'metric_run_id' => $e->metric_run_id,
                'niche_version_id' => $e->niche_version_id,
            ]),
        ]]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (DB::table('hypotheses')->where('id', $id)->doesntExist()) {
            return response()->json(['code' => 'HYPOTHESIS_NOT_FOUND', 'message' => 'Гипотеза не найдена.'], 404);
        }
        $data = $this->validated($request, isUpdate: true);
        $update = ['updated_at' => now()];
        foreach ([
            'niche_id', 'title', 'audience', 'problem', 'current_solution', 'product_idea',
            'mvp', 'monetization', 'notes', 'research_links', 'next_experiment',
            'success_criterion', 'status', 'status_reason', 'dataset_id',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }
        DB::table('hypotheses')->where('id', $id)->update($update);

        return $this->show($id);
    }

    public function destroy(string $id): JsonResponse
    {
        DB::table('hypotheses')->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Подтверждающие фразы с сохранённой оценкой (ТЗ §5.6).
     */
    public function addEvidence(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'keyword_id' => ['required', 'integer'],
            'dataset_id' => ['required', 'uuid'],
            'metric_run_id' => ['nullable', 'uuid'],
            'niche_version_id' => ['nullable', 'uuid'],
            'snapshot' => ['nullable', 'array'],
        ]);

        $keyword = DB::table('keywords')->where('id', $data['keyword_id'])->first();
        if ($keyword === null) {
            return response()->json(['code' => 'KEYWORD_NOT_FOUND', 'message' => 'Фраза не найдена.'], 404);
        }

        $snapshot = $data['snapshot'] ?? [
            'phrase' => $keyword->phrase_original,
            'saved_at' => now()->toDateTimeString(),
        ];

        $exists = DB::table('hypothesis_evidence')
            ->where('hypothesis_id', $id)->where('keyword_id', $data['keyword_id'])->exists();
        if ($exists) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        DB::table('hypothesis_evidence')->insert([
            'hypothesis_id' => $id,
            'keyword_id' => $data['keyword_id'],
            'dataset_id' => $data['dataset_id'],
            'metric_run_id' => $data['metric_run_id'] ?? null,
            'niche_version_id' => $data['niche_version_id'] ?? null,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function removeEvidence(string $id, int $keywordId): JsonResponse
    {
        DB::table('hypothesis_evidence')
            ->where('hypothesis_id', $id)->where('keyword_id', $keywordId)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Экспорт Markdown-карточки — фоновый экспорт (ТЗ §11).
     */
    public function export(string $id): JsonResponse
    {
        if (DB::table('hypotheses')->where('id', $id)->doesntExist()) {
            return response()->json(['code' => 'HYPOTHESIS_NOT_FOUND', 'message' => 'Гипотеза не найдена.'], 404);
        }
        $jobId = ExportProcessor::queue('hypothesis_md', ['hypothesis_id' => $id]);

        return response()->json(['export_job_id' => $jobId], 202);
    }

    private function format(object $h): array
    {
        return [
            'id' => $h->id,
            'title' => $h->title,
            'niche_id' => $h->niche_id,
            'niche_name' => $h->niche_name ?? null,
            'status' => $h->status,
            'status_label' => self::STATUS_LABELS[$h->status] ?? $h->status,
            'status_reason' => $h->status_reason,
            'audience' => $h->audience,
            'problem' => $h->problem,
            'current_solution' => $h->current_solution,
            'product_idea' => $h->product_idea,
            'mvp' => $h->mvp,
            'monetization' => $h->monetization,
            'notes' => $h->notes,
            'research_links' => $h->research_links,
            'next_experiment' => $h->next_experiment,
            'success_criterion' => $h->success_criterion,
            'dataset_id' => $h->dataset_id,
            'created_at' => $h->created_at,
            'updated_at' => $h->updated_at,
        ];
    }

    private function validated(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'title' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:300'],
            'niche_id' => ['nullable', 'uuid'],
            'dataset_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'status_reason' => ['nullable', 'string', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:4000'],
            'problem' => ['nullable', 'string', 'max:4000'],
            'current_solution' => ['nullable', 'string', 'max:4000'],
            'product_idea' => ['nullable', 'string', 'max:4000'],
            'mvp' => ['nullable', 'string', 'max:4000'],
            'monetization' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string'],
            'research_links' => ['nullable', 'string', 'max:4000'],
            'next_experiment' => ['nullable', 'string', 'max:4000'],
            'success_criterion' => ['nullable', 'string', 'max:4000'],
        ]);
    }
}
