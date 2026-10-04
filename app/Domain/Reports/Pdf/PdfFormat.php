<?php

namespace App\Domain\Reports\Pdf;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Formato de las cifras en el PDF y en la impresión (D-140), como en la app (resources/js/lib/
 * format.ts): horas en h:mm, importes en euros con coma decimal, porcentajes con un decimal y
 * fechas dd/mm/aaaa.
 */
final class PdfFormat
{
    /** 150 → «2:30»; -30 → «-0:30». */
    public static function minutes(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return $sign.intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** Exceso con su signo: «+1:40», o «0:00» si no hay. */
    public static function overage(int $minutes): string
    {
        return ($minutes > 0 ? '+' : '').self::minutes($minutes);
    }

    /** "1234.5" → «1.234,50 €»; null → «». */
    public static function money(?string $amount): string
    {
        return $amount === null || $amount === '' ? '' : number_format((float) $amount, 2, ',', '.').' €';
    }

    /** 0,3944 → «39,4 %»; null → «—». */
    public static function percent(?float $ratio, int $decimals = 1): string
    {
        return $ratio === null ? '—' : number_format($ratio * 100, $decimals, ',', '.').' %';
    }

    public static function number(int|float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    public static function date(?string $date): string
    {
        return $date === null || $date === '' ? '' : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    /** «2026-09-01» → «septiembre de 2026». */
    public static function month(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return self::monthName($day->month).' de '.$day->year;
    }

    public static function monthName(int $month): string
    {
        return self::text('report_pdf.months.'.$month);
    }

    /**
     * Periodo legible: «septiembre de 2026», «3.er trimestre de 2026», «año 2026», «semana del
     * 21/09/2026 al 27/09/2026» o «del 01/09/2026 al 15/09/2026».
     */
    public static function period(ReportFilters $filters): string
    {
        $from = $filters->from;
        $to = $filters->to;

        return match ($filters->period) {
            ReportPeriod::Month => self::month($from->toDateString()),
            ReportPeriod::Quarter => self::text('report_pdf.period.quarter', ['quarter' => (string) $from->quarter, 'year' => (string) $from->year]),
            ReportPeriod::Year => self::text('report_pdf.period.year', ['year' => (string) $from->year]),
            ReportPeriod::Week => self::text('report_pdf.period.week', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]),
            ReportPeriod::Range => self::text('report_pdf.period.range', ['from' => $from->format('d/m/Y'), 'to' => $to->format('d/m/Y')]),
        };
    }

    /**
     * El periodo para el nombre del fichero: 2026-09, 2026-t3, 2026, 2026-s39 o 2026-09-01-2026-09-15.
     */
    public static function periodSlug(ReportFilters $filters): string
    {
        $from = $filters->from;

        return match ($filters->period) {
            ReportPeriod::Month => $from->format('Y-m'),
            ReportPeriod::Quarter => $from->year.'-t'.$from->quarter,
            ReportPeriod::Year => (string) $from->year,
            ReportPeriod::Week => $from->isoWeekYear.'-s'.str_pad((string) $from->isoWeek, 2, '0', STR_PAD_LEFT),
            ReportPeriod::Range => $from->toDateString().'-'.$filters->to->toDateString(),
        };
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
