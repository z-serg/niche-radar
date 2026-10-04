<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Собственные метки и заметки на уровне фраз (ТЗ §5.2, §5.3, §7.3).
 * Ручное переопределение хранится отдельно от машинных меток.
 */
class LabelsController extends Controller
{
    public function addLabel(Request $request, int $keywordId): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
        ]);
        $label = trim($data['label']);
        if ($label === '') {
            return response()->json(['code' => 'INVALID_LABEL'], 422);
        }

        $exists = DB::table('keywords')->where('id', $keywordId)->exists();
        if (! $exists) {
            return response()->json(['code' => 'KEYWORD_NOT_FOUND'], 404);
        }

        DB::table('keyword_labels')->insert([
            'keyword_id' => $keywordId,
            'label' => $label,
            'origin' => 'manual',
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function removeLabel(Request $request, int $keywordId): JsonResponse
    {
        $label = (string) $request->query('label', '');
        DB::table('keyword_labels')
            ->where('keyword_id', $keywordId)
            ->where('origin', 'manual')
            ->where('label', $label)
            ->delete();

        return response()->json(['deleted' => true]);
    }

    public function addNote(Request $request, int $keywordId): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $id = DB::table('keyword_notes')->insertGetId([
            'keyword_id' => $keywordId,
            'body' => $data['body'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function removeNote(int $keywordId, int $noteId): JsonResponse
    {
        DB::table('keyword_notes')->where('id', $noteId)->where('keyword_id', $keywordId)->delete();

        return response()->json(['deleted' => true]);
    }
}
