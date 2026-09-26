<?php

namespace App\Domain\Time;

/**
 * Textos del área de horas (lang/es/time.php) como string, también con plurales (trans_choice).
 */
final class Messages
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        return trans_choice($key, $count, ['count' => $count, ...$replace]);
    }
}
