<?php

namespace App\Support;

/**
 * Duraciones en minutos enteros (SPEC §7, D-036). Gemelo de resources/js/lib/duration.ts:
 * ambos pasan los mismos casos (tests/Unit/DurationTest.php y tests/js/duration.test.ts).
 *
 * Formatos aceptados: "1:30", "1.5", "1,5", "90m", "90min", "1h30", "1h 30m", "2h" y "2" (horas).
 */
final class Duration
{
    public const int MAX_MINUTES = 24 * 60;

    /**
     * Minutos de un texto, o null si no es una duración válida (entre 1 minuto y $max; por
     * defecto 24 horas, el máximo de una imputación; las estimaciones usan un máximo mayor).
     */
    public static function parse(?string $input, int $max = self::MAX_MINUTES): ?int
    {
        $text = preg_replace('/\s+/u', '', mb_strtolower(trim((string) $input))) ?? '';

        if ($text === '') {
            return null;
        }

        $minutes = null;

        if (preg_match('/^(\d{1,4}):([0-5]\d)$/', $text, $m) === 1) {
            $minutes = (int) $m[1] * 60 + (int) $m[2];
        } elseif (preg_match('/^(\d+)(?:m|min)$/', $text, $m) === 1) {
            $minutes = (int) $m[1];
        } elseif (preg_match('/^(\d+)h(?:([0-5]?\d)(?:m|min)?)?$/', $text, $m) === 1) {
            $minutes = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        } elseif (preg_match('/^(\d+(?:[.,]\d+)?)h?$/', $text, $m) === 1) {
            $minutes = (int) round((float) str_replace(',', '.', $m[1]) * 60);
        }

        if ($minutes === null || $minutes <= 0 || $minutes > $max) {
            return null;
        }

        return $minutes;
    }

    /**
     * "h:mm" (1:05, 25:00, -0:30). Igual que formatMinutes() de resources/js/lib/format.ts.
     */
    public static function format(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Redondea al múltiplo más cercano de $step (temporizador, D-036). Con $step ≤ 1, sin cambios.
     */
    public static function roundToNearest(int $minutes, int $step): int
    {
        if ($step <= 1) {
            return $minutes;
        }

        return (int) (round($minutes / $step) * $step);
    }
}
