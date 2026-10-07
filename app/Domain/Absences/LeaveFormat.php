<?php

namespace App\Domain\Absences;

use App\Enums\LeaveUnit;
use App\Support\Duration;

/**
 * Cantidades de los saldos en texto (Fase 11, R3; D-361): «22 días», «1 día», «0,5 días»,
 * «12,83 días» o «16:00 h». Gemelo de formatLeaveAmount() de resources/js/lib/leave.ts.
 */
final class LeaveFormat
{
    public static function amount(int $amount, LeaveUnit $unit): string
    {
        if ($unit === LeaveUnit::Hours) {
            return (string) __('leave.units.hours', ['value' => Duration::format($amount)]);
        }

        $days = self::days($amount);

        return (string) trans_choice('leave.units.days', abs($amount) === LeaveCatalog::DAY ? 1 : 2, ['value' => $days]);
    }

    /** Centésimas de día en texto, con coma y sin ceros de más: 2200 → «22», 1250 → «12,5». */
    public static function days(int $amount): string
    {
        $text = number_format($amount / LeaveCatalog::DAY, 2, ',', '');

        return rtrim(rtrim($text, '0'), ',');
    }

    /**
     * Una cantidad escrita a mano o en el CSV de Woffu: días («12,5», «12.5») o horas («16:00»,
     * «16,5», «16h30»). null si no se entiende. Admite el signo menos.
     */
    public static function parse(string $value, LeaveUnit $unit): ?int
    {
        $text = trim($value);
        $sign = 1;

        if (str_starts_with($text, '-')) {
            $sign = -1;
            $text = ltrim(substr($text, 1));
        }

        if ($text === '') {
            return null;
        }

        if ($unit === LeaveUnit::Hours) {
            if (preg_match('/^0+([.,:]0+)?$/', $text) === 1) {
                return 0;
            }

            $minutes = Duration::parse($text, 1000 * 60);

            return $minutes === null ? null : $sign * $minutes;
        }

        if (preg_match('/^\d{1,4}([.,]\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        return $sign * (int) round((float) str_replace(',', '.', $text) * LeaveCatalog::DAY);
    }
}
