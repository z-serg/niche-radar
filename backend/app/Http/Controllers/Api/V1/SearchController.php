<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Datasets\DatasetManager;
use App\Domain\Search\FilterPayload;
use App\Domain\Search\KeywordSearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly KeywordSearchService $search) {}

    /**
     * POST /keywords/search — поиск, фильтрация, курсорная пагинация (ТЗ §9).
     */
    public function search(Request $request): JsonResponse
    {
        $body = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'metric_run_id' => ['nullable', 'uuid'],
            'filters' => ['nullable', 'array'],
            'sort' => ['nullable', 'array'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'cursor' => ['nullable', 'string'],
        ]);

        $datasetId = $body['dataset_id'];
        $this->assertReady($datasetId);

        try {
            $payload = new FilterPayload($body);
            $result = $this->search->search($datasetId, $body['metric_run_id'] ?? null, $payload);
        } catch (\RuntimeException $e) {
            return $this->domainError($e);
        }

        return response()->json($result + [
            'dataset_id' => $datasetId,
            'metric_run_id' => $body['metric_run_id'] ?? null,
            'total_count' => null, // точное число необязательно (ТЗ §9); считать отдельно
        ]);
    }

    /**
     * POST /keywords/count — точное число совпадений (асинхронно по смыслу,
     * вызывается UI отдельно, ТЗ §5.2).
     */
    public function count(Request $request): JsonResponse
    {
        $body = $request->validate([
            'dataset_id' => ['required', 'uuid'],
            'metric_run_id' => ['nullable', 'uuid'],
            'filters' => ['nullable', 'array'],
            'sort' => ['nullable', 'array'],
        ]);
        $this->assertReady($body['dataset_id']);

        try {
            $payload = new FilterPayload($body);
            $n = $this->search->count($body['dataset_id'], $body['metric_run_id'] ?? null, $payload);
        } catch (\RuntimeException $e) {
            return $this->domainError($e);
        }

        return response()->json(['total_count' => $n]);
    }

    private function assertReady(string $datasetId): JsonResponse|null
    {
        $status = \Illuminate\Support\Facades\DB::table('datasets')->where('id', $datasetId)->value('status');
        if ($status !== 'ready') {
            return response()->json([
                'code' => 'DATASET_NOT_READY',
                'message' => "Отчёт не опубликован (статус: {$status}); поиск доступен только по опубликованным отчётам.",
            ], 409)->throwResponse();
        }

        return null;
    }

    private function domainError(\RuntimeException $e): JsonResponse
    {
        $code = strtok($e->getMessage(), ':') ?: 'QUERY_FAILED';

        return response()->json(['code' => $code, 'message' => $e->getMessage()], 422);
    }
}
