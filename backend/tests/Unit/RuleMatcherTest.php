<?php

namespace Tests\Unit;

use App\Domain\Rules\RuleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Проверка стартового словаря признаков задачи на демонстрационных
 * примерах (ТЗ §7.3).
 */
class RuleMatcherTest extends TestCase
{
    private array $rules;

    protected function setUp(): void
    {
        $this->rules = require __DIR__.'/../../app/Domain/Rules/task_signals_v1.php';
    }

    private function match(string $phrase): array
    {
        $search = mb_strtolower($phrase, 'UTF-8');

        return (new RuleMatcher())->match($this->rules, $search, \App\Support\Tokenizer::searchTokens($search));
    }

    public function test_converter_is_strong_signal(): void
    {
        $m = $this->match('конвертер pdf в word онлайн');

        $this->assertSame(1.0, $m['score']);
        $this->assertContains('conversion', $m['categories']);
    }

    public function test_pdf_to_pair_combination(): void
    {
        $m = $this->match('как из pdf в jpg сделать');

        $this->assertSame(0.9, $m['score']);
        $this->assertContains('conversion', $m['categories']);
    }

    public function test_calculator(): void
    {
        $m = $this->match('калькулятор калорий для похудения');

        $this->assertSame(0.9, $m['score']);
        $this->assertContains('calculation', $m['categories']);
    }

    public function test_weak_online_alone(): void
    {
        $m = $this->match('фильмы онлайн смотреть');

        $this->assertSame(0.2, $m['score'], 'одиночное «онлайн» — слабый сигнал');
        $this->assertContains('weak', $m['categories']);
    }

    public function test_no_match_scores_zero(): void
    {
        $m = $this->match('котики смешные картинки');

        $this->assertSame(0.0, $m['score']);
        $this->assertSame([], $m['categories']);
    }

    public function test_max_weight_wins(): void
    {
        // Слабый сигнал «скачать» и сильная комбинация одновременно.
        $m = $this->match('скачать с ютуба видео');

        $this->assertSame(0.7, $m['score']);
        $this->assertContains('parsing', $m['categories']);
    }
}
