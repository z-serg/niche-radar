<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Response;

/**
 * Общая механика MCP-инструментов: постраничная выдача (по умолчанию 50,
 * максимум 200 строк), ограничение сериализованного ответа 256 KiB с
 * явным truncated и курсором, продолжающим с первой невыданной строки
 * (ТЗ §10).
 */
trait McpToolSupport
{
    private function limit(?int $requested): int
    {
        return max(1, min((int) ($requested ?? 50), 200));
    }

    /**
     * Ограничить массив строк по числу и по размеру сериализованного ответа.
     *
     * @param  array<int, array>  $rows
     * @return array{rows: array, truncated: bool, next_cursor: ?string, notes: string[]}
     */
    private function cap(array $rows, int $limit, ?string $cursorKey = 'cursor', ?callable $cursorOf = null): array
    {
        $maxBytes = (int) config('niche.mcp_response_limit_bytes', 256 * 1024);
        $notes = [];
        $out = [];
        $size = 0;
        $truncated = false;
        $nextCursor = null;

        foreach ($rows as $i => $row) {
            if (count($out) >= $limit) {
                $truncated = true;
                $nextCursor = $cursorOf !== null ? $cursorOf($row) : null;
                break;
            }
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                continue;
            }
            if ($size + strlen($encoded) > $maxBytes && $out !== []) {
                $truncated = true;
                $notes[] = 'Ответ ограничен 256 KiB; продолжайте курсором с первой невыданной строки.';
                $nextCursor = $cursorOf !== null ? $cursorOf($row) : null;
                break;
            }
            $size += strlen($encoded);
            $out[] = $row;
        }
        // Строки, не попавшие в выдачу из-за лимита числа, тоже truncated.
        if (count($out) < count($rows) && ! $truncated) {
            $truncated = true;
            $nextCursor = null; // числовой лимит: продолжение через limit/offset-подход
            $notes[] = 'Выданы не все строки: уменьшите фильтры или используйте курсор сервиса.';
        }

        return ['rows' => $out, 'truncated' => $truncated, 'next_cursor' => $nextCursor, 'notes' => $notes];
    }

    /**
     * Ответ JSON с обязательными метаданными ограничений.
     */
    private function jsonResponse(array $payload): Response
    {
        $payload['meta'] = array_merge([
            'units' => 'частотности: целые числа показов за период источника; рост: доли (0.3 = +30%)',
            'missing_values_rule' => 'null = значение отсутствует (пустая ячейка); 0 = наблюдаемый ноль; отсутствие строки = фразы нет в отчёте',
            'source_limitations' => 'даты замеров могут быть неизвестны: ось порядковая, календарные темпы не рассчитываются',
        ], $payload['meta'] ?? []);

        return Response::json($payload);
    }
}
