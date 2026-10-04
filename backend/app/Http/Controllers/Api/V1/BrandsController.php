<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Словари брендов для исключения из поиска (ТЗ §5.2).
 */
class BrandsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('brand_dictionaries')->orderBy('name')->get()->map(fn ($b) => $this->format($b)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'terms' => ['required', 'array', 'min:1'],
            'terms.*' => ['string', 'max:100'],
        ]);
        $id = DB::table('brand_dictionaries')->insertGetId([
            'name' => $data['name'],
            'terms' => json_encode(array_values(array_unique(array_map(
                fn ($t) => mb_strtolower(trim((string) $t), 'UTF-8'),
                $data['terms']
            ))), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'terms' => ['nullable', 'array', 'min:1'],
            'terms.*' => ['string', 'max:100'],
        ]);
        $update = ['updated_at' => now()];
        if (isset($data['name'])) {
            $update['name'] = $data['name'];
        }
        if (isset($data['terms'])) {
            $update['terms'] = json_encode(array_values(array_unique(array_map(
                fn ($t) => mb_strtolower(trim((string) $t), 'UTF-8'),
                $data['terms']
            ))), JSON_UNESCAPED_UNICODE);
        }
        DB::table('brand_dictionaries')->where('id', $id)->update($update);

        return $this->show($id);
    }

    public function show(int $id): JsonResponse
    {
        $b = DB::table('brand_dictionaries')->where('id', $id)->first();
        if ($b === null) {
            return response()->json(['code' => 'BRAND_NOT_FOUND'], 404);
        }

        return response()->json(['data' => $this->format($b)]);
    }

    public function destroy(int $id): JsonResponse
    {
        DB::table('brand_dictionaries')->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }

    private function format(object $b): array
    {
        return [
            'id' => (int) $b->id,
            'name' => $b->name,
            'terms' => json_decode((string) $b->terms, true) ?: [],
            'created_at' => $b->created_at,
        ];
    }
}
