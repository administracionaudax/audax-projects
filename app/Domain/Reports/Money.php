<?php

namespace App\Domain\Reports;

/**
 * Importes exactos con bcmath (nunca float, CLAUDE.md): strings decimales como "1234.50".
 * Se opera con 6 decimales y se redondea a 2 al presentar.
 */
final class Money
{
    public const int SCALE = 6;

    /**
     * @return numeric-string
     */
    public static function of(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, self::SCALE, '.', '');
        }

        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        return '0';
    }

    /**
     * @return numeric-string
     */
    public static function add(string ...$values): string
    {
        $total = '0';
        foreach ($values as $value) {
            $total = bcadd($total, self::of($value), self::SCALE);
        }

        return $total;
    }

    /**
     * @return numeric-string
     */
    public static function sub(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function mul(string $a, string $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function div(string $a, string $b): string
    {
        $divisor = self::of($b);

        return bccomp($divisor, '0', self::SCALE) === 0 ? '0' : bcdiv(self::of($a), $divisor, self::SCALE);
    }

    /**
     * Importe de $minutes a $hourlyRate €/h.
     */
    /**
     * @return numeric-string
     */
    public static function forMinutes(int|string $minutes, ?string $hourlyRate): string
    {
        return $hourlyRate === null ? '0' : self::div(self::mul(self::of((string) $minutes), $hourlyRate), '60');
    }

    /**
     * @return numeric-string
     */
    public static function round(string $value): string
    {
        return bcround(self::of($value), 2);
    }

    public static function isZero(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) === 0;
    }
}
