<?php

namespace App\Support;

/**
 * Экранирование значений для COPY ... FROM STDIN в текстовом формате
 * PostgreSQL: backslash, tab, перевод строки, возврат каретки; NULL -> \N.
 */
final class CopyFormat
{
    public static function field(?string $value): string
    {
        if ($value === null) {
            return '\N';
        }

        return str_replace(
            ['\\', "\t", "\n", "\r"],
            ['\\\\', '\t', '\n', '\r'],
            $value
        );
    }

    public static function intOrNull(null|int|string $value): string
    {
        if ($value === null || $value === '') {
            return '\N';
        }

        return (string) (int) $value;
    }

    /**
     * @param  array<int, string>  $fields
     */
    public static function line(array $fields): string
    {
        return implode("\t", $fields)."\n";
    }
}
