<?php

namespace App\Domain\Weeklies\Help;

use Carbon\CarbonImmutable;

/**
 * Las novedades automáticas del centro de ayuda (F-150 y F-152), port de `helpCenter.ts` de
 * WeeklySync (puro, sin base de datos):
 * - una versión por semana del mes, «V.serie.mes.semana»: la serie es el año desde 2026 (2026 = 1),
 *   la semana del mes es (día − 1) / 7 + 1, como mucho 5,
 * - su fecha de publicación es el día (semana − 1) · 7 + 5 del mes (acotado al último día),
 * - está «en curso» si es la de esta semana, aún no ha llegado su fecha y no tiene contenido propio
 *   (ni un resumen distinto del de por defecto ni cambios),
 * - en el listado, la más reciente que no está en curso es la «nueva»; las demás, «anteriores».
 */
final class HelpReleaseCalendar
{
    public const int SERIES_BASE_YEAR = 2026;

    public const string STATUS_NEW = 'new';

    public const string STATUS_IN_PROGRESS = 'in_progress';

    public const string STATUS_PREVIOUS = 'previous';

    /**
     * La versión de una fecha (de Madrid).
     *
     * @return array{major_version: int, month_number: int, week_of_month: int}
     */
    public static function versionFor(CarbonImmutable $date): array
    {
        return [
            'major_version' => max(1, $date->year - self::SERIES_BASE_YEAR + 1),
            'month_number' => $date->month,
            'week_of_month' => self::weekOfMonth($date),
        ];
    }

    public static function weekOfMonth(CarbonImmutable $date): int
    {
        return min(5, intdiv($date->day - 1, 7) + 1);
    }

    /** «V.1.10.2». */
    public static function label(int $major, int $month, int $week): string
    {
        return "V.{$major}.{$month}.{$week}";
    }

    /** Fecha de publicación (Y-m-d) de una versión. */
    public static function publishedOn(int $major, int $month, int $week): string
    {
        $year = self::SERIES_BASE_YEAR + $major - 1;
        $first = CarbonImmutable::create($year, max(1, min(12, $month)), 1);
        $day = min($first->daysInMonth, ($week - 1) * 7 + 5);

        return $first->setDay(max(1, $day))->toDateString();
    }

    /**
     * ¿Tiene contenido propio? Un resumen que no es el de por defecto o algún cambio.
     */
    public static function hasContent(string $summary, int $changes, string $defaultSummary): bool
    {
        $summary = trim($summary);

        return ($summary !== '' && $summary !== trim($defaultSummary)) || $changes > 0;
    }

    /**
     * ¿Está en curso? La de esta semana, sin contenido propio, hasta su fecha de publicación.
     */
    public static function isInProgress(int $major, int $month, int $week, bool $hasContent, CarbonImmutable $today): bool
    {
        if ($hasContent) {
            return false;
        }

        $current = self::versionFor($today);

        if ($current !== ['major_version' => $major, 'month_number' => $month, 'week_of_month' => $week]) {
            return false;
        }

        return $today->toDateString() <= self::publishedOn($major, $month, $week);
    }

    /**
     * Ordena las entradas (fecha de publicación descendente y, a igual fecha, por su clave descendente,
     * como WeeklySync) y les pone el estado: la primera que no está en curso es la nueva.
     *
     * @param  list<array<string, mixed>>  $entries  cada una con key, published_on e in_progress
     * @return list<array<string, mixed>> las mismas, con status
     */
    public static function withStatuses(array $entries): array
    {
        usort($entries, fn (array $a, array $b): int => $a['published_on'] === $b['published_on']
            ? strcmp((string) $b['key'], (string) $a['key'])
            : strcmp((string) $b['published_on'], (string) $a['published_on']));

        $newAssigned = false;
        $result = [];

        foreach ($entries as $entry) {
            if ($entry['in_progress']) {
                $status = self::STATUS_IN_PROGRESS;
            } elseif (! $newAssigned) {
                $status = self::STATUS_NEW;
                $newAssigned = true;
            } else {
                $status = self::STATUS_PREVIOUS;
            }

            $result[] = $entry + ['status' => $status];
        }

        return $result;
    }
}
