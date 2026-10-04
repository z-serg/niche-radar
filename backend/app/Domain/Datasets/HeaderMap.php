<?php

namespace App\Domain\Datasets;

/**
 * Результат сопоставления колонок CSV-отчёта семантическим ролям.
 * Сопоставление — по именам заголовков после CSV-разбора (ТЗ §4.3),
 * порядок колонок не используется.
 */
final class HeaderMap
{
    public function __construct(
        public readonly string $delimiter,
        /** @var array<int, string> индекс колонки => роль */
        public readonly array $roles,
        /** @var array<int, array{ordinal: int, suffix: ?string, broad_col: ?int, exact_col: ?int, headers: array{?string, ?string}}>*/
        public readonly array $scans,
        public readonly ?int $rankCurrentCol,
        public readonly ?int $rankPreviousCol,
        public readonly ?int $phraseCol,
        public readonly ?int $wordCountCol,
        public readonly ?int $charCountCol,
        /** Ненумерованные «текущие» колонки (дублируют замер с суффиксом 1). */
        public readonly ?int $currentBroadCol,
        public readonly ?int $currentExactCol,
        /** @var array<int, string> индекс => исходный заголовок (не распознан) */
        public readonly array $unknown,
        /** @var string[] список причин неполноты сопоставления */
        public readonly array $problems,
    ) {}

    public function isValid(): bool
    {
        return $this->phraseCol !== null
            && $this->scanCount() >= 1
            && $this->newestExactCol() !== null
            && $this->problems === [];
    }

    public function scanCount(): int
    {
        return count($this->scans);
    }

    /**
     * Максимальный индекс распознанной колонки (для контроля ширины записей).
     */
    public function maxColumnIndex(): int
    {
        $indexes = array_keys($this->roles);
        if ($this->currentBroadCol !== null) {
            $indexes[] = $this->currentBroadCol;
        }
        if ($this->currentExactCol !== null) {
            $indexes[] = $this->currentExactCol;
        }

        return $indexes ? max($indexes) : -1;
    }

    /**
     * Колонка, из которой берётся текущая точная частотность.
     * Массив scans упорядочен от старого (ordinal 1) к новому.
     */
    public function newestExactCol(): ?int
    {
        $newest = $this->scans[count($this->scans) - 1] ?? null;

        return $newest['exact_col'] ?? $this->currentExactCol;
    }

    public function toArray(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'roles' => $this->roles,
            'scans' => $this->scans,
            'rank_current_col' => $this->rankCurrentCol,
            'rank_previous_col' => $this->rankPreviousCol,
            'phrase_col' => $this->phraseCol,
            'word_count_col' => $this->wordCountCol,
            'char_count_col' => $this->charCountCol,
            'current_broad_col' => $this->currentBroadCol,
            'current_exact_col' => $this->currentExactCol,
            'unknown' => $this->unknown,
            'problems' => $this->problems,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            delimiter: $data['delimiter'] ?? ';',
            roles: $data['roles'] ?? [],
            scans: array_values($data['scans'] ?? []),
            rankCurrentCol: $data['rank_current_col'] ?? null,
            rankPreviousCol: $data['rank_previous_col'] ?? null,
            phraseCol: $data['phrase_col'] ?? null,
            wordCountCol: $data['word_count_col'] ?? null,
            charCountCol: $data['char_count_col'] ?? null,
            currentBroadCol: $data['current_broad_col'] ?? null,
            currentExactCol: $data['current_exact_col'] ?? null,
            unknown: $data['unknown'] ?? [],
            problems: $data['problems'] ?? [],
        );
    }
}
