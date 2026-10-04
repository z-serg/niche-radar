<?php

namespace App\Support;

/**
 * Токенизация фраз. Базовый режим (ТЗ §5.2): токен — непрерывная
 * последовательность Unicode-букв или цифр. Морфологические варианты
 * автоматически не добавляются.
 */
final class Tokenizer
{
    /**
     * Токены фразы в исходном порядке вхождения.
     *
     * @return string[]
     */
    public static function tokens(string $phrase): array
    {
        preg_match_all('/[\p{L}\p{Nd}]+/u', $phrase, $m);

        return $m[0];
    }

    /**
     * Токены нижнего регистра для поиска.
     *
     * @return string[]
     */
    public static function searchTokens(string $searchText): array
    {
        $tokens = self::tokens($searchText);

        return array_map(fn ($t) => mb_strtolower($t, 'UTF-8'), $tokens);
    }

    /**
     * Соседние пары токенов (для предложений групп, ТЗ §7.6).
     *
     * @param  string[]  $tokens
     * @return string[]  «токен1 токен2»
     */
    public static function bigrams(array $tokens): array
    {
        $result = [];
        $n = count($tokens);
        for ($i = 0; $i < $n - 1; $i++) {
            $result[] = $tokens[$i].' '.$tokens[$i + 1];
        }

        return $result;
    }

    /**
     * Число слов по собственному правилу (число токенов).
     */
    public static function wordCount(string $phrase): int
    {
        return count(self::tokens($phrase));
    }

    /**
     * Число Unicode-символов (не байт).
     */
    public static function charCount(string $phrase): int
    {
        return mb_strlen($phrase, 'UTF-8');
    }
}
