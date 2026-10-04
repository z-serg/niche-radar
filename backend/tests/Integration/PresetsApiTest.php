<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/presets — описания пресетов для заполнения фильтров UI.
 */
class PresetsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_all_presets_with_thresholds_and_sort(): void
    {
        $response = $this->getJson('/api/v1/presets');

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach (['sustained_growth', 'early_signals', 'zero_baseline', 'growth_leaders', 'fall_leaders'] as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertNotEmpty($data[$key]['title']);
            $this->assertIsArray($data[$key]['sort']);
        }

        // Пороги «Устойчивого роста» — для заполнения фильтров UI.
        $t = $data['sustained_growth']['thresholds'];
        $this->assertSame(100, (int) $t['exact_current_min']);
        $this->assertSame(20, (int) $t['base_min']);
        $this->assertSame(50, (int) $t['smoothed_delta_min']);
        $this->assertEquals(0.3, (float) $t['smoothed_growth_min']);
        $this->assertEquals(0.6, (float) $t['consistency_min']);
        $this->assertEquals(0.7, (float) $t['peak_retention_min']);

        // «Лидеры падения» — без селективных порогов (только требование
        // полного ряда), сортировка по A по возрастанию.
        foreach (['exact_current_min', 'base_min', 'smoothed_delta_min', 'smoothed_growth_min', 'consistency_min', 'peak_retention_min'] as $absent) {
            $this->assertArrayNotHasKey($absent, $data['fall_leaders']['thresholds']);
        }
        $this->assertSame(4, (int) $data['fall_leaders']['thresholds']['min_observations']);
        $this->assertSame('smoothed_delta', $data['fall_leaders']['sort'][0]['field']);
        $this->assertSame('asc', $data['fall_leaders']['sort'][0]['direction']);

        // «Рост от нуля» — признак нулевой базы.
        $this->assertTrue((bool) $data['zero_baseline']['thresholds']['base_zero']);
    }

    public function test_available_without_auth(): void
    {
        // Локальное приложение: аутентификации нет, REST открыт.
        $this->getJson('/api/v1/presets')->assertStatus(200);
    }
}
