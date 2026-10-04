<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavedSearchesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('saved_searches')->orderBy('name')->get()->map(fn ($s) => $this->format($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'filters' => ['required', 'array'],
            'report_mode' => ['nullable', 'in:fixed,latest'],
            'dataset_id' => ['nullable', 'uuid'],
        ]);
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('saved_searches')->insert([
            'id' => $id,
            'name' => $data['name'],
            'filters' => json_encode($data['filters'], JSON_UNESCAPED_UNICODE),
            'schema_version' => 1,
            'report_mode' => $data['report_mode'] ?? 'latest',
            'dataset_id' => $data['dataset_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function show(string $id): JsonResponse
    {
        $s = DB::table('saved_searches')->where('id', $id)->first();
        if ($s === null) {
            return response()->json(['code' => 'SAVED_SEARCH_NOT_FOUND', 'message' => 'Поиск не найден.'], 404);
        }

        return response()->json(['data' => $this->format($s)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'filters' => ['nullable', 'array'],
            'report_mode' => ['nullable', 'in:fixed,latest'],
            'dataset_id' => ['nullable', 'uuid'],
        ]);
        $update = ['updated_at' => now()];
        foreach (['name', 'report_mode', 'dataset_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f];
            }
        }
        if (isset($data['filters'])) {
            $update['filters'] = json_encode($data['filters'], JSON_UNESCAPED_UNICODE);
        }
        DB::table('saved_searches')->where('id', $id)->update($update);

        return $this->show($id);
    }

    public function destroy(string $id): JsonResponse
    {
        DB::table('saved_searches')->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }

    private function format(object $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'filters' => json_decode((string) $s->filters, true),
            'schema_version' => (int) $s->schema_version,
            'report_mode' => $s->report_mode,
            'dataset_id' => $s->dataset_id,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
    }
}
