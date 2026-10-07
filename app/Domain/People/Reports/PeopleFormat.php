<?php

namespace App\Domain\People\Reports;

use App\Enums\WorkdayIncident;
use App\Enums\WorkMode;
use App\Support\Duration;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Formato de los ficheros del registro (R2, D-351): duraciones en h:mm, instantes en la hora de
 * Madrid, fechas dd/mm/aaaa y los nombres de modos e incidencias. Los CSV y Excel llevan los minutos
 * como números enteros (exactos y tratables, como pide la ITSS); los PDF, en h:mm.
 */
final class PeopleFormat
{
    public static function hm(int $minutes): string
    {
        return Duration::format($minutes);
    }

    /** «+0:30», «-0:15» o «0:00»; vacío si no hay diferencia (hoy sin cerrar, futuro). */
    public static function difference(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return ($minutes > 0 ? '+' : '').Duration::format($minutes);
    }

    /** Hora de Madrid («09:05») de un instante ISO o de una fecha. */
    public static function time(string|DateTimeInterface|null $instant): string
    {
        if ($instant === null || $instant === '') {
            return '';
        }

        return self::local($instant)->format('H:i');
    }

    /** «dd/mm/aaaa hh:mm» en la hora de Madrid. */
    public static function dateTime(string|DateTimeInterface|null $instant): string
    {
        if ($instant === null || $instant === '') {
            return '';
        }

        return self::local($instant)->format('d/m/Y H:i');
    }

    /** «dd/mm/aaaa hh:mm:ss» en la hora de Madrid (los fichajes, con segundos). */
    public static function dateTimeSeconds(string|DateTimeInterface|null $instant): string
    {
        if ($instant === null || $instant === '') {
            return '';
        }

        return self::local($instant)->format('d/m/Y H:i:s');
    }

    /** «06/10/2026» a partir de «2026-10-06». */
    public static function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private const array WEEKDAYS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

    private const array MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** «lun 06/10». */
    public static function day(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return self::WEEKDAYS[$day->dayOfWeekIso - 1].' '.$day->format('d/m');
    }

    /** «septiembre de 2026». */
    public static function month(string $month): string
    {
        $first = CarbonImmutable::parse(substr($month, 0, 7).'-01');

        return self::MONTHS[$first->month - 1].' de '.$first->year;
    }

    /**
     * Tramos de trabajo («09:00–14:00, 15:00–18:00») y comida aparte.
     *
     * @param  list<array{kind: string, from: string, to: string|null, work_mode: string|null}>  $segments
     * @return array{work: string, pause: string}
     */
    public static function segments(array $segments): array
    {
        $work = [];
        $pause = [];

        foreach ($segments as $segment) {
            $label = self::time($segment['from']).'–'.($segment['to'] === null ? '…' : self::time($segment['to']));

            if ($segment['kind'] === 'work') {
                $work[] = $label;
            } else {
                $pause[] = $label;
            }
        }

        return ['work' => implode(', ', $work), 'pause' => implode(', ', $pause)];
    }

    /**
     * @param  list<string>  $modes
     */
    public static function modes(array $modes): string
    {
        return implode(', ', array_map(fn (string $mode): string => WorkMode::tryFrom($mode)?->label() ?? $mode, $modes));
    }

    /**
     * @param  list<string>  $incidents
     */
    public static function incidents(array $incidents): string
    {
        return implode('; ', array_map(fn (string $incident): string => WorkdayIncident::tryFrom($incident)?->label() ?? $incident, $incidents));
    }

    private static function local(string|DateTimeInterface $instant): CarbonImmutable
    {
        $value = is_string($instant) ? CarbonImmutable::parse($instant) : CarbonImmutable::instance($instant);

        return $value->setTimezone(LocalTime::timezone());
    }
}
