<?php

namespace Tests\Unit;

use App\Domain\Search\Presets;
use Tests\TestCase;

/**
 * Пресеты отбора: единые условия для UI, REST и MCP (ТЗ §7.2).
 */
class PresetsTest extends TestCase
{
    public function test_sustained_growth_conditions(): void
    {
        $sql = Presets::conditions('sustained_growth', 'km');

        $this->assertStringContainsString('km.n_observations >= 4', $sql);
        $this->assertStringContainsString('km.history_complete', $sql);
        $this->assertStringContainsString('km.exact_current >= 100', $sql);
        $this->assertStringContainsString('km.base_avg >= 20', $sql);
        $this->assertStringContainsString('km.smoothed_delta >= 50', $sql);
        $this->assertStringContainsString('km.consistency >= 0.6', $sql);
    }

    public function test_leaders_presets_match_dashboard_cards(): void
    {
        // Полный ряд и наличие A — без порогов; сортировка по A (§5.7).
        $growth = Presets::conditions('growth_leaders', 'km');
        $this->assertStringContainsString('km.n_observations >= 4', $growth);
        $this->assertStringContainsString('km.history_complete', $growth);
        $this->assertStringContainsString('km.smoothed_delta IS NOT NULL', $growth);
        $this->assertStringNotContainsString('exact_current', $growth, 'порогов нет — как у карточек дашборда');

        $this->assertSame(
            [['field' => 'smoothed_delta', 'direction' => 'desc']],
            Presets::defaultSort('growth_leaders'),
        );
        $this->assertSame(
            [['field' => 'smoothed_delta', 'direction' => 'asc']],
            Presets::defaultSort('fall_leaders'),
        );
    }

    public function test_describe_explains_reliability(): void
    {
        $this->assertSame(
            'основной рейтинг устойчивого роста',
            Presets::describe('sustained_growth')['reliability'],
        );
        $this->assertStringContainsString(
            'пониженной надёжности',
            Presets::describe('early_signals')['reliability'],
        );
        $this->assertStringContainsString(
            'ранжирование по сглаженному приросту A без порогов',
            Presets::describe('growth_leaders')['reliability'],
        );
    }
}
