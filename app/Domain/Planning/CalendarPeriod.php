<?php

namespace App\Domain\Planning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Periodo visible del calendario de tareas (D-061), a partir de la URL:
 * - ?mes=2026-10: el mes, con semanas completas de lunes a domingo (del lunes de la semana del
 *   día 1 al domingo de la semana del último día: 4, 5 o 6 filas),
 * - ?semana=2026-10-05: la semana (de lunes a domingo) que contiene ese día,
 * - sin nada (o con valores no válidos): el mes de hoy.
 * Trabaja con fechas locales Y-m-d, sin zona horaria (las fechas de tarea no se convierten).
 */
final readonly class CalendarPeriod
{
    public const string MONTH = 'month';

    public const string WEEK = 'week';

    private const int MIN_YEAR = 2000;

    private const int MAX_YEAR = 2100;

    /**
     * @param  self::MONTH|self::WEEK  $mode
     */
    private function __construct(
        public string $mode,
        /** «2026-10» (mes) o el lunes «2026-10-05» (semana). */
        public string $key,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function resolve(mixed $month, mixed $week, CarbonImmutable $today): self
    {
        $monday = self::validDate($week);

        if ($monday !== null) {
            $monday = $monday->startOfWeek(CarbonInterface::MONDAY);

            return new self(self::WEEK, $monday->toDateString(), $monday, $monday->addDays(6));
        }

        $first = self::validMonth($month) ?? CarbonImmutable::parse($today->toDateString())->startOfMonth();

        return new self(
            self::MONTH,
            $first->format('Y-m'),
            $first->startOfWeek(CarbonInterface::MONDAY),
            $first->endOfMonth()->startOfDay()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay(),
        );
    }

    public function fromString(): string
    {
        return $this->from->toDateString();
    }

    public function toString(): string
    {
        return $this->to->toDateString();
    }

    private static function validDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        [, $year, $month, $day] = array_map(intval(...), $parts);

        if (! checkdate($month, $day, $year) || $year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day);
    }

    private static function validMonth(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];

        if ($month < 1 || $month > 12 || $year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return null;
        }

        return CarbonImmutable::create($year, $month, 1);
    }
}
