<?php

namespace Tests\Unit;

use App\Domain\Metrics\MetricFormulas;
use PHPUnit\Framework\TestCase;

/**
 * Контрольные ряды раздела 14.1 ТЗ.
 */
class MetricFormulasTest extends TestCase
{
    public function test_row_100_120_150_180(): void
    {
        $m = MetricFormulas::compute([100, 120, 150, 180]);

        $this->assertSame(110.0, $m['base_avg']);
        $this->assertSame(165.0, $m['late_avg']);
        $this->assertSame(55.0, $m['smoothed_delta']);
        $this->assertSame(0.5, $m['smoothed_growth']);
        $this->assertSame(1.0, $m['consistency']);
        $this->assertSame(1.0, $m['peak_retention']);
        $this->assertSame(80, $m['exact_delta']);
        $this->assertSame(0.8, $m['growth_pct']);
    }

    public function test_priority_v1_equals_45(): void
    {
        $profile = [
            'g_scale' => 2.0,
            'a_scale' => 10000.0,
            'v_scale' => 100000.0,
            'weights' => ['g' => 0.30, 'a' => 0.25, 'c' => 0.20, 'v' => 0.15, 't' => 0.10],
            'min_base_for_priority' => 20.0,
        ];

        // Ряд [100,120,150,180], T=0.
        $this->assertSame(45, MetricFormulas::priority(110.0, 0.5, 55.0, 1.0, 180.0, 0.0, $profile));
    }

    public function test_flat_row_fails_sustained_growth(): void
    {
        $m = MetricFormulas::compute([100, 100, 100, 100]);

        $this->assertSame(0.0, $m['smoothed_delta']);
        $this->assertSame(0.0, $m['smoothed_growth']);
        $this->assertSame(0.0, $m['consistency']);
        $this->assertTrue($m['peak_retention'] === 1.0);
    }

    public function test_spike_row_peak_retention_low(): void
    {
        $m = MetricFormulas::compute([100, 100, 1000, 110]);

        $this->assertEqualsWithDelta(0.11, $m['peak_retention'], 0.005);
        $this->assertEqualsWithDelta(1 / 3, $m['consistency'], 1e-9);
    }

    public function test_zero_baseline_row(): void
    {
        $m = MetricFormulas::compute([0, 0, 100, 200]);

        $this->assertSame(0.0, $m['base_avg']);
        $this->assertSame(150.0, $m['smoothed_delta']);
        $this->assertNull($m['smoothed_growth']);
        $this->assertSame('zero_baseline', $m['null_reasons']['smoothed_growth']);
        $this->assertSame('zero_baseline', $m['null_reasons']['growth_pct']);
        $this->assertSame(200, $m['exact_delta']);
        $this->assertTrue($m['low_base']);
    }

    public function test_incomplete_row_smoothed_null_but_boundary_available(): void
    {
        $m = MetricFormulas::compute([100, null, 150, 180]);

        $this->assertNull($m['base_avg']);
        $this->assertNull($m['smoothed_delta']);
        $this->assertSame('incomplete_series', $m['null_reasons']['smoothed']);
        $this->assertSame(80, $m['exact_delta']); // граничный прирост доступен
    }

    public function test_short_row_insufficient_history(): void
    {
        $m = MetricFormulas::compute([100, 120]);

        $this->assertNull($m['smoothed_delta']);
        $this->assertSame('insufficient_history', $m['null_reasons']['smoothed']);
        $this->assertSame(20, $m['exact_delta']);
    }

    public function test_priority_null_for_low_base(): void
    {
        $profile = [
            'g_scale' => 2.0, 'a_scale' => 10000.0, 'v_scale' => 100000.0,
            'weights' => ['g' => 0.30, 'a' => 0.25, 'c' => 0.20, 'v' => 0.15, 't' => 0.10],
            'min_base_for_priority' => 20.0,
        ];

        $this->assertNull(MetricFormulas::priority(5.0, 3.0, 500.0, 1.0, 2000.0, 1.0, $profile));
        $this->assertNull(MetricFormulas::priority(null, 3.0, 500.0, 1.0, 2000.0, 1.0, $profile));
    }

    public function test_zero_to_zero_growth_undefined(): void
    {
        $m = MetricFormulas::compute([0, 0, 0, 0]);

        $this->assertNull($m['growth_pct']);
        $this->assertSame(0, $m['exact_delta']);
        $this->assertNull($m['peak_retention']); // нулевой пик
        $this->assertSame('zero_peak', $m['null_reasons']['peak_retention']);
    }

    public function test_task_score_contributes_ten_percent(): void
    {
        $profile = [
            'g_scale' => 2.0, 'a_scale' => 10000.0, 'v_scale' => 100000.0,
            'weights' => ['g' => 0.30, 'a' => 0.25, 'c' => 0.20, 'v' => 0.15, 't' => 0.10],
            'min_base_for_priority' => 20.0,
        ];

        $zero = MetricFormulas::priority(110.0, 0.5, 55.0, 1.0, 180.0, 0.0, $profile);
        $one = MetricFormulas::priority(110.0, 0.5, 55.0, 1.0, 180.0, 1.0, $profile);

        // Вклад T не превышает 10 баллов из 100: 45.195 → 45, 55.195 → 55.
        $this->assertSame(45, $zero);
        $this->assertSame(55, $one);
    }
}
