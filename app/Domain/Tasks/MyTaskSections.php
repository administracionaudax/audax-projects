<?php

namespace App\Domain\Tasks;

use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Secciones de Mis tareas (SPEC §6, D-037), con «hoy» en Europe/Madrid (App\Support\LocalTime):
 * - vencidas: vencimiento anterior a hoy,
 * - hoy: vencimiento o inicio hoy,
 * - esta semana: vencimiento hasta el domingo,
 * - próximas: el resto con alguna fecha,
 * - sin fecha.
 * Se comparan fechas de calendario ("Y-m-d"), nunca instantes: una fecha no tiene zona horaria.
 */
final class MyTaskSections
{
    public const string OVERDUE = 'overdue';

    public const string TODAY = 'today';

    public const string THIS_WEEK = 'this_week';

    public const string UPCOMING = 'upcoming';

    public const string NO_DATE = 'no_date';

    public const array ORDER = [self::OVERDUE, self::TODAY, self::THIS_WEEK, self::UPCOMING, self::NO_DATE];

    /**
     * @param  CarbonImmutable  $today  hoy en Madrid (LocalTime::today())
     */
    public function sectionOf(Task $task, CarbonImmutable $today): string
    {
        $todayDate = $today->toDateString();
        $sunday = $today->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
        $due = $task->due_date?->toDateString();
        $start = $task->start_date?->toDateString();

        return match (true) {
            $due !== null && $due < $todayDate => self::OVERDUE,
            $due === $todayDate || $start === $todayDate => self::TODAY,
            $due !== null && $due <= $sunday => self::THIS_WEEK,
            $due !== null || $start !== null => self::UPCOMING,
            default => self::NO_DATE,
        };
    }
}
