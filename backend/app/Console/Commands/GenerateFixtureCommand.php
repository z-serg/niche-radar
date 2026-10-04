<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Воспроизводимый генератор контрольного CSV (ТЗ §13): реалистичные
 * распределения длины фраз, частотностей (Ципф), общих токенов и типов
 * динамики. Схема совпадает с Top10.csv (19 колонок, BOM, `;`, CRLF).
 */
class GenerateFixtureCommand extends Command
{
    protected $signature = 'niche:generate-fixture
        {--rows=3000000 : число строк данных}
        {--out= : путь к файлу (по умолчанию storage/app/staging)}
        {--seed=42 : зерно генератора}';

    protected $description = 'Сгенерировать синтетический CSV-отчёт контрольного объёма';

    private const HEADWORDS = ['конвертер', 'рассчитать', 'калькулятор', 'генератор', 'сжать', 'объединить',
        'сравнить', 'отследить', 'расписание', 'учет', 'погода', 'переводчик', 'карта', 'курс', 'рецепт',
        'тренировка', 'дневник', 'план', 'чек', 'заявление', 'справка', 'бланк', 'образец', 'онлайн', 'бесплатно'];
    private const TAILWORDS = ['pdf', 'jpg', 'png', 'word', 'excel', 'онлайн', 'калорий', 'беременности',
        'для', 'домашнего', '2016', 'windows', 'linux', 'android', 'айфон', 'ноутбука', 'сайта', 'фото',
        'видео', 'музыки', 'файла', 'таблицу', 'excel в', 'из', 'в', 'с', 'без'];

    public function handle(): int
    {
        $rows = (int) $this->option('rows');
        $seed = (int) $this->option('seed');
        $out = $this->option('out') ?: storage_path("app/staging/bench_{$rows}_{$seed}.csv");
        @mkdir(dirname($out), 0775, true);

        mt_srand($seed);
        $handle = fopen($out, 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fwrite($handle, '"#";"Предыдущий #";"Ключевое слово";"Слов";"Символов";"Частотность Весь мир";"Частотность Весь мир 6";"Частотность Весь мир 5";"Частотность Весь мир 4";"Частотность Весь мир 3";"Частотность Весь мир 2";"Частотность Весь мир 1";"""[!Частотность !Весь !мир]""";"""[!Частотность !Весь !мир]"" 6";"""[!Частотность !Весь !мир]"" 5";"""[!Частотность !Весь !мир]"" 4";"""[!Частотность !Весь !мир]"" 3";"""[!Частотность !Весь !мир]"" 2";"""[!Частотность !Весь !мир]"" 1"\r\n');

        $started = microtime(true);
        $this->info("Генерация {$rows} строк в {$out}...");
        $this->output->progressStart($rows);

        for ($i = 1; $i <= $rows; $i++) {
            $words = $this->phrase();
            $phrase = implode(' ', $words);
            $wordCount = count($words);
            $charCount = mb_strlen($phrase, 'UTF-8');

            // Тип динамики: 55% стабильные, 15% рост, 10% спад, 10% spike, 10% от нуля.
            $kind = mt_rand(1, 100);
            $exact = $this->series($kind);
            $broad = array_map(fn ($v) => (int) round($v * (2.5 + mt_rand(0, 200) / 100)), $exact);
            $currentBroad = $broad[5];
            $prevRank = $kind <= 10 && mt_rand(0, 4) === 0 ? 0 : max(1, $i - mt_rand(-50, 50000));

            $cells = [
                $i, $prevRank, '"'.$phrase.'"', $wordCount, $charCount, $currentBroad,
                $broad[0], $broad[1], $broad[2], $broad[3], $broad[4], $broad[5],
                $exact[5], $exact[0], $exact[1], $exact[2], $exact[3], $exact[4], $exact[5],
            ];
            fwrite($handle, implode(';', $cells)."\r\n");

            if ($i % 100000 === 0) {
                $this->output->progressAdvance(100000);
            }
        }

        $this->output->progressFinish();
        fclose($handle);

        $this->info(sprintf('Готово: %s (%.1f МБ, %.1f с)', $out, filesize($out) / 1048576, microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function phrase(): array
    {
        $n = mt_rand(1, 6);
        $words = [self::HEADWORDS[array_rand(self::HEADWORDS)]];
        for ($i = 1; $i < $n; $i++) {
            $words[] = self::TAILWORDS[array_rand(self::TAILWORDS)];
        }

        return $words;
    }

    /**
     * @return array<int, int> шесть точных частотностей от старой к новой
     */
    private function series(int $kind): array
    {
        $base = (int) round(30_000_000 / pow(mt_rand(1, 3_000_000), 0.62)); // Ципф-подобный хвост
        $base = max($base, 5);
        $noise = fn ($x) => (int) max(0, round($x * (0.95 + mt_rand(0, 1000) / 10000)));

        $series = [];
        if ($kind <= 55) {
            // стабильные
            $series = [$noise($base), $noise($base), $noise($base), $noise($base), $noise($base), $noise($base)];
        } elseif ($kind <= 70) {
            // устойчивый рост
            $g = 1 + mt_rand(15, 60) / 100;
            $v = max(20, $base * 0.05);
            for ($i = 0; $i < 6; $i++) {
                $series[$i] = $noise($v);
                $v *= $g;
            }
        } elseif ($kind <= 80) {
            // спад
            $v = max(30, $base * 0.3);
            for ($i = 0; $i < 6; $i++) {
                $series[$i] = $noise($v);
                $v *= 0.75;
            }
        } elseif ($kind <= 90) {
            // всплеск в последнем замере
            $series = [$noise($base * 0.01), $noise($base * 0.01), $noise($base * 0.01), $noise($base * 0.012), $noise($base * 0.013), $noise($base)];
        } else {
            // рост от нуля
            $series = [0, 0, $noise(max(3, $base * 0.001)), $noise(max(10, $base * 0.01)), $noise(max(20, $base * 0.05)), $noise(max(60, $base * 0.2))];
        }

        return $series;
    }
}
