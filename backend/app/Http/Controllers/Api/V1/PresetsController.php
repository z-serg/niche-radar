<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\Presets;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Описания пресетов отбора: пороги и сортировка по умолчанию. Используются
 * UI для заполнения фильтров значениями пресета (единый источник —
 * config/niche.presets, тот же, что для REST и MCP).
 */
class PresetsController extends Controller
{
    public function index(): JsonResponse
    {
        $data = [];
        foreach (array_keys(config('niche.presets')) as $key) {
            $info = Presets::describe($key);
            $info['sort'] = Presets::defaultSort($key);
            $data[$key] = $info;
        }

        return response()->json(['data' => $data]);
    }
}
