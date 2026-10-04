<?php

namespace App\Domain\Datasets;

use App\Support\CsvStreamer;

/**
 * Адаптер CSV-отчётов Bukvarix/Яндекс Wordstat (ТЗ §4.3, §6.1).
 *
 * Заголовки распознаются по именам и алиасам после стандартного CSV-разбора;
 * вложенные кавычки точной частотности — часть имени. Вид частотности
 * определяется структурой имени (суффикс/кавычки), а не порядком колонок.
 * Суффикс N = номер замера от последнего (1 — новейший), внутренний
 * ordinal 1 — самый старый: ordinal = число замеров − суффикс + 1.
 */
final class BukvarixCsvAdapter
{
    public const SOURCE = 'bukvarix';

    private const BROAD_BASE = 'Частотность Весь мир';
    private const EXACT_BASE = '"[!Частотность !Весь !мир]"';
    // Алиас из readme.txt (§4.3).
    private const BROAD_ALIASES = ['Частотность "[!Весь !мир]"'];

    /**
     * Сопоставление заголовков файла.
     *
     * @param  string[]  $headers  имена колонок после CSV-разбора
     */
    public function map(array $headers, string $delimiter): HeaderMap
    {
        $roles = [];
        $rankCur = $rankPrev = $phrase = $wc = $cc = null;
        $curBroad = $curExact = null;
        $unknown = [];

        // suffix => ['broad' => col, 'exact' => col]
        $numbered = [];
        $headerNames = [];

        foreach ($headers as $i => $raw) {
            $name = self::normalize((string) $raw);
            $headerNames[$i] = (string) $raw;

            if ($name === '#') {
                $roles[$i] = 'rank_current';
                $rankCur = $i;
                continue;
            }
            if ($name === 'Предыдущий #') {
                $roles[$i] = 'rank_previous';
                $rankPrev = $i;
                continue;
            }
            if ($name === 'Ключевое слово') {
                $roles[$i] = 'phrase';
                $phrase = $i;
                continue;
            }
            if ($name === 'Слов') {
                $roles[$i] = 'word_count_source';
                $wc = $i;
                continue;
            }
            if ($name === 'Символов') {
                $roles[$i] = 'char_count_source';
                $cc = $i;
                continue;
            }

            // Точная семейство: имя в кавычках с восклицательными знаками.
            if (preg_match('/^('.preg_quote(self::EXACT_BASE, '/').')\s+(\d+)$/u', $name, $m) === 1) {
                $suffix = (int) $m[2];
                $numbered[$suffix]['exact'] = $i;
                $roles[$i] = 'exact_observation';
                continue;
            }
            if ($name === self::EXACT_BASE) {
                $roles[$i] = 'exact_current_unnumbered';
                $curExact = $i;
                continue;
            }

            // Широкая семья.
            if (preg_match('/^('.preg_quote(self::BROAD_BASE, '/').')\s+(\d+)$/u', $name, $m) === 1) {
                $suffix = (int) $m[2];
                $numbered[$suffix]['broad'] = $i;
                $roles[$i] = 'broad_observation';
                continue;
            }
            if ($name === self::BROAD_BASE || in_array($name, self::BROAD_ALIASES, true)) {
                $roles[$i] = 'broad_current_unnumbered';
                $curBroad = $i;
                continue;
            }

            $unknown[$i] = (string) $raw;
        }

        // Замеры: суффиксы по убыванию = от старого к новому.
        krsort($numbered);
        $scans = [];
        $ordinal = 1;
        foreach ($numbered as $suffix => $cols) {
            $scans[] = [
                'ordinal' => $ordinal,
                'suffix' => (string) $suffix,
                'broad_col' => $cols['broad'] ?? null,
                'exact_col' => $cols['exact'] ?? null,
                'headers' => [
                    $cols['broad'] !== null ? $headerNames[$cols['broad']] : null,
                    $cols['exact'] !== null ? $headerNames[$cols['exact']] : null,
                ],
            ];
            $ordinal++;
        }

        // Файл только с ненумерованными колонками: один текущий замер.
        if ($scans === [] && ($curExact !== null || $curBroad !== null)) {
            $scans[] = [
                'ordinal' => 1,
                'suffix' => null,
                'broad_col' => null,
                'exact_col' => null,
                'headers' => [$curBroad !== null ? $headerNames[$curBroad] : null, $curExact !== null ? $headerNames[$curExact] : null],
            ];
        }

        $problems = [];
        if ($phrase === null) {
            $problems[] = 'not_found:phrase';
        }
        if ($rankCur === null) {
            $problems[] = 'not_found:rank_current';
        }
        if ($scans === []) {
            $problems[] = 'not_found:observations';
        }
        // scans упорядочены от старого к новому: последний элемент — новейший.
        $newest = ($scans[count($scans) - 1]['exact_col'] ?? null) ?? $curExact;
        if ($newest === null) {
            $problems[] = 'not_found:exact_current';
        }
        if ($unknown !== []) {
            $problems[] = 'unknown_headers:'.implode(',', array_keys($unknown));
        }

        return new HeaderMap(
            delimiter: $delimiter,
            roles: $roles,
            scans: $scans,
            rankCurrentCol: $rankCur,
            rankPreviousCol: $rankPrev,
            phraseCol: $phrase,
            wordCountCol: $wc,
            charCountCol: $cc,
            currentBroadCol: $curBroad,
            currentExactCol: $curExact,
            unknown: $unknown,
            problems: $problems,
        );
    }

    /**
     * Автоопределение по файлу: разделитель + заголовок + сопоставление.
     */
    public function mapFile(string $path): HeaderMap
    {
        $streamer = new CsvStreamer($path);
        $delimiter = $streamer->detectDelimiter();
        $headers = $streamer->header();

        return $this->map($headers, $delimiter);
    }

    /**
     * Нормализация имени заголовка: trim + свёртка внутренних пробелов.
     */
    public static function normalize(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }
}
