<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Интеграционные тесты работают в отдельной БД niche_radar_test.
        // Рабочая база не должна быть доступна тестам ни при каких env.
        if (config('database.default') === 'pgsql' && env('DB_DATABASE') !== 'niche_radar_test') {
            $this->fail('ТЕСТ ЗАПУЩЕН ПРОТИВ РАБОЧЕЙ БД — используйте ./bin/test');
        }
    }
}
